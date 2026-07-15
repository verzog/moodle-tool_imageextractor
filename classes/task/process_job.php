<?php
// Copyright (c) Skin Cancer College Australasia.
// All rights reserved.
//
// This file is part of a proprietary plugin developed by Skin Cancer
// College Australasia for use with Moodle. It is NOT free software and is
// NOT released under the GNU General Public License.
//
// Unauthorised copying, distribution, modification, or use of this file,
// in whole or in part, via any medium, is strictly prohibited without the
// prior written permission of Skin Cancer College Australasia. The software
// is provided "as is", without warranty of any kind, express or implied.

/**
 * Adhoc task that matches files and packs them into ZIP volumes.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;
use tool_imageextractor\matcher;
use tool_imageextractor\naming;

/**
 * Processes one extraction job, building at most one ZIP volume per run and
 * re-queuing itself until every matched file has been packed.
 *
 * Splitting the work one volume per run keeps each cron tick bounded (roughly
 * the configured volume size) so a 50GB job never blocks a worker for hours,
 * and makes the whole job resumable: an interrupted run leaves the remaining
 * files marked pending for the next tick.
 */
class process_job extends \core\task\adhoc_task {
    /** @var array Cache of contextid => course info for naming. */
    protected $coursecache = [];

    /** @var array Cache of contextid => module info for the export metadata. */
    protected $modulecache = [];

    /**
     * Cap how many of these run in parallel. Packing ZIP volumes is IO-heavy,
     * so the default of 1 keeps a big job from starving the rest of the queue.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        $configured = (int) get_config('tool_imageextractor', 'process_concurrency');
        return $configured > 0 ? $configured : 1;
    }

    /**
     * Entry point.
     */
    public function execute() {
        global $DB;

        $data = (object) $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        // A fresh run defers its (potentially large) clear of the previous
        // run's items and volumes to here, off the web request. Re-queued
        // continuation tasks do not set this.
        $clearfirst = !empty($data->clearfirst);
        if ($jobid <= 0) {
            return;
        }

        $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);
        if (!$job) {
            mtrace('tool_imageextractor: job ' . $jobid . ' no longer exists, skipping');
            return;
        }

        if (!manager::is_enabled()) {
            // Throw rather than return so Moodle reschedules this adhoc task and
            // the job resumes once the plugin is re-enabled, instead of the task
            // being consumed and the job left stuck as queued forever.
            throw new \moodle_exception('disabledretry', 'tool_imageextractor');
        }

        if (!in_array($job->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true)) {
            mtrace('tool_imageextractor: job ' . $jobid . ' is "' . $job->status . '", nothing to do');
            return;
        }

        \core_php_time_limit::raise(0);
        \raise_memory_limit(MEMORY_EXTRA);

        try {
            if ($job->status === manager::STATUS_QUEUED) {
                $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_PROCESSING, ['id' => $jobid]);
                $DB->set_field('tool_imageextractor_job', 'timestarted', time(), ['id' => $jobid]);
                // Clear any stale results here (deferred off the web request)
                // before matching, so a re-run starts from a clean slate
                // without duplicating items.
                if ($clearfirst) {
                    manager::clear_results($jobid);
                }
                // The web flow analyses first, so the matched item rows already
                // exist and this task only packs them. The CLI/direct path
                // queues packing straight away with nothing matched yet, so it
                // matches here before packing (mirroring the replace task's
                // direct-apply fallback).
                if (!$DB->record_exists('tool_imageextractor_item', ['jobid' => $jobid])) {
                    $this->prepare($job);
                }
                $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid], '*', MUST_EXIST);
            }

            if ((int) $job->totalmatched === 0) {
                $this->finalise($job);
                return;
            }

            $this->process_one_volume($job);
        } catch (\Throwable $e) {
            // A genuine failure (bad criteria, storage error) should not retry
            // forever, so record it and stop rather than rethrowing.
            $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_FAILED, ['id' => $jobid]);
            $DB->set_field('tool_imageextractor_job', 'error', $e->getMessage(), ['id' => $jobid]);
            mtrace('tool_imageextractor: job ' . $jobid . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Match files and record one item row per file to be exported.
     *
     * @param \stdClass $job
     * @return void
     */
    protected function prepare(\stdClass $job) {
        global $DB;

        mtrace('tool_imageextractor: preparing job ' . $job->id . ' ("' . $job->name . '")');
        $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_PROCESSING, ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'timestarted', time(), ['id' => $job->id]);

        $matcher = new matcher(manager::decode_criteria($job), (bool) $job->dedupe);
        $rs = $matcher->get_recordset();

        $seen = [];
        $lasthash = null;
        $count = 0;
        $bytes = 0;
        $batch = [];

        foreach ($rs as $file) {
            // With dedupe on, the recordset is ordered by hash; skip repeats.
            if ($job->dedupe && $file->contenthash === $lasthash) {
                continue;
            }
            $lasthash = $file->contenthash;

            $courseinfo = $this->resolve_course((int) $file->contextid);
            $outputname = naming::render($job->namingrule, [
                'originalname'    => $file->filename,
                'fileid'          => $file->id,
                'contenthash'     => $file->contenthash,
                'component'       => $file->component,
                'filearea'        => $file->filearea,
                'itemid'          => $file->itemid,
                'courseid'        => $courseinfo->courseid,
                'coursename'      => $courseinfo->fullname,
                'courseshortname' => $courseinfo->shortname,
                'uploaderid'      => $file->userid,
                'mimetype'        => $file->mimetype,
                'seq'             => $count + 1,
                'date'            => userdate((int) $file->timecreated, '%Y%m%d'),
            ]);
            $outputname = naming::ensure_unique($outputname, $seen);

            $item = new \stdClass();
            $item->jobid = $job->id;
            $item->fileid = (int) $file->id;
            $item->contenthash = $file->contenthash;
            $item->filename = $file->filename;
            $item->filesize = (int) $file->filesize;
            $item->mimetype = $file->mimetype;
            $item->contextid = (int) $file->contextid;
            $item->component = $file->component;
            $item->filearea = $file->filearea;
            $item->filepath = $file->filepath;
            $item->fileitemid = (int) $file->itemid;
            $item->uploaderid = (int) $file->userid;
            $item->author = $file->author ?? null;
            $item->license = $file->license ?? null;
            $item->imagewidth = 0;
            $item->imageheight = 0;
            $item->filetimecreated = (int) $file->timecreated;
            $item->courseid = (int) $courseinfo->courseid;
            $item->courseshortname = $courseinfo->shortname;
            $item->outputname = $outputname;
            $item->volume = 0;
            $item->status = 'pending';
            $item->timeprocessed = 0;
            $batch[] = $item;

            $count++;
            $bytes += (int) $file->filesize;

            if (count($batch) >= 1000) {
                $DB->insert_records('tool_imageextractor_item', $batch);
                $batch = [];
            }
        }
        if ($batch) {
            $DB->insert_records('tool_imageextractor_item', $batch);
        }
        $rs->close();

        $DB->set_field('tool_imageextractor_job', 'totalmatched', $count, ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'totalbytes', $bytes, ['id' => $job->id]);
        mtrace('tool_imageextractor: job ' . $job->id . ' matched ' . $count . ' files (' . display_size($bytes) . ')');
    }

    /**
     * Pack the next batch of pending items into a single ZIP volume.
     *
     * @param \stdClass $job
     * @return void
     */
    protected function process_one_volume(\stdClass $job) {
        global $DB;

        $fs = get_file_storage();
        $context = manager::context();
        $sequence = (int) $job->volumecount + 1;

        $archivefiles = [];
        $doneids = [];
        $failedids = [];
        $volumebytes = 0;
        // A volume is its own ZIP, so output names only need to be unique within
        // it; this map guards against two files in this volume colliding.
        $seen = [];
        // Per-item fields computed this run (packed name and resolved course),
        // keyed by item id and persisted alongside the done status so the
        // manifest and sidecar report what actually went into the archive.
        $persist = [];
        // Sequence numbers continue after everything packed in earlier volumes.
        $seqbase = (int) $job->processedcount;
        // Bound this run by file count as well as volume size. Each file costs
        // a get_file_by_id (a mdl_files query), so with a large volume size one
        // run could otherwise fetch millions of files before the throttle delay
        // ever applies - exactly the database saturation the throttle prevents.
        // Capping the volume here means a volume holds at most batch_size files
        // and spills into the next (paced) run.
        $batchsize = manager::batch_size();

        $rs = $DB->get_recordset(
            'tool_imageextractor_item',
            ['jobid' => $job->id, 'status' => 'pending'],
            'id ASC'
        );
        foreach ($rs as $item) {
            // Stop once this run has examined a full batch (counting files that
            // failed the check below - each still cost one query).
            if (count($doneids) + count($failedids) >= $batchsize) {
                break;
            }

            $stored = $fs->get_file_by_id((int) $item->fileid);
            // Skip files that have vanished or whose content is missing on disk -
            // the ZIP packer would otherwise silently drop them and the manifest
            // would wrongly report a successful export.
            if (!$stored || $stored->is_directory() || \tool_imageextractor\replacer::content_missing($stored)) {
                $failedids[] = (int) $item->id;
                continue;
            }

            // The type-agnostic match records courseid=0 and a blank course
            // shortname; resolve the real course now (cached) so the sidecar
            // and manifest carry it rather than 0/blank.
            $courseinfo = $this->resolve_course((int) $item->contextid);
            $item->courseid = (int) $courseinfo->courseid;
            $item->courseshortname = $courseinfo->shortname;

            // Image dimensions for the export metadata; false for non-images.
            $imageinfo = $stored->get_imageinfo();
            $item->imagewidth = $imageinfo ? (int) $imageinfo['width'] : 0;
            $item->imageheight = $imageinfo ? (int) $imageinfo['height'] : 0;

            // The activity the file lives in (cached per context), so the
            // sidecar names the module instance ("Lesson 1") it came from.
            $moduleinfo = $this->resolve_module((int) $item->contextid);
            $item->cmid = $moduleinfo->cmid;
            $item->modname = $moduleinfo->modname;
            $item->modulename = $moduleinfo->modulename;

            // The image's description (alt text), read from the HTML that
            // embeds it. Blank when the file is not embedded in a mapped
            // rich-text field, or is embedded without a description.
            $item->alttext = $this->resolve_alt($item);

            // The type-agnostic match left the output name blank; compute it now
            // from the job's naming rule and the item's stored fields (the same
            // placeholders as before). A pre-computed name (the CLI path) is
            // reused as-is.
            $outputname = $item->outputname !== '' ? $item->outputname
                : naming::ensure_unique($this->render_name($job, $item, $seqbase + count($doneids) + 1), $seen);
            $item->outputname = $outputname;
            $persist[(int) $item->id] = (object) [
                'id'              => (int) $item->id,
                'outputname'      => $outputname,
                'courseid'        => $item->courseid,
                'courseshortname' => $item->courseshortname,
                'imagewidth'      => $item->imagewidth,
                'imageheight'     => $item->imageheight,
                'alttext'         => $item->alttext,
            ];

            $entry = 'images/' . $outputname;
            $archivefiles[$entry] = $stored;
            $archivefiles[$entry . '.json'] = [$this->sidecar_json($item)];

            $doneids[] = (int) $item->id;
            $volumebytes += (int) $item->filesize;

            // One file per volume minimum, then stop once the size cap is reached.
            if ($volumebytes >= (int) $job->volumesize) {
                break;
            }
        }
        $rs->close();

        $now = time();

        if ($archivefiles) {
            $tempdir = make_request_directory();
            $temppath = $tempdir . '/volume.zip';
            $packer = get_file_packer('application/zip');
            $packer->archive_to_pathname($archivefiles, $temppath);

            // Volumes are stored under the job id (not the sequence) so two
            // jobs writing volume 1 cannot overwrite each other's archive.
            $filename = 'images-volume-' . sprintf('%03d', $sequence) . '.zip';
            $filerecord = [
                'contextid' => $context->id,
                'component' => manager::COMPONENT,
                'filearea'  => 'volumes',
                'itemid'    => (int) $job->id,
                'filepath'  => '/',
                'filename'  => $filename,
            ];
            // Replace just this volume's file if a stale copy exists; leave the
            // job's earlier volumes untouched.
            if (
                $existingzip = $fs->get_file(
                    $context->id,
                    manager::COMPONENT,
                    'volumes',
                    (int) $job->id,
                    '/',
                    $filename
                )
            ) {
                $existingzip->delete();
            }
            $storedzip = $fs->create_file_from_pathname($filerecord, $temppath);

            $volume = new \stdClass();
            $volume->jobid = (int) $job->id;
            $volume->sequence = $sequence;
            $volume->filename = $filename;
            $volume->filesize = (int) $storedzip->get_filesize();
            $volume->filecount = count($doneids);
            $volume->timecreated = $now;
            $DB->insert_record('tool_imageextractor_volume', $volume);

            $DB->set_field('tool_imageextractor_job', 'volumecount', $sequence, ['id' => $job->id]);
            mtrace('tool_imageextractor: job ' . $job->id . ' wrote ' . $filename .
                ' (' . count($doneids) . ' files, ' . display_size($volume->filesize) . ')');
        }

        if ($doneids) {
            // Persist the packed name and the resolved course for each file so
            // the manifest and the sidecar reflect what actually went into the
            // archive.
            foreach ($persist as $rec) {
                $DB->update_record('tool_imageextractor_item', $rec);
            }
            $this->mark_items($doneids, 'done', $sequence, $now);
            $DB->execute(
                'UPDATE {tool_imageextractor_job}
                    SET processedcount = processedcount + :c, processedbytes = processedbytes + :b
                  WHERE id = :id',
                ['c' => count($doneids), 'b' => $volumebytes, 'id' => $job->id]
            );
        }
        if ($failedids) {
            $this->mark_items($failedids, 'failed', 0, $now);
            $DB->execute(
                'UPDATE {tool_imageextractor_job} SET failedcount = failedcount + :c WHERE id = :id',
                ['c' => count($failedids), 'id' => $job->id]
            );
        }

        $remaining = $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $job->id, 'status' => 'pending']
        );
        if ($remaining > 0) {
            // More to do: re-queue ourselves for the next volume. Pace it into
            // the future so the database rests between volumes instead of one
            // cron run packing volume after volume and starving the site.
            $task = new process_job();
            $task->set_custom_data(['jobid' => (int) $job->id]);
            $delay = manager::throttle_delay();
            if ($delay > 0) {
                $task->set_next_run_time(time() + $delay);
            }
            \core\task\manager::queue_adhoc_task($task);
            mtrace('tool_imageextractor: job ' . $job->id . ' has ' . $remaining . ' files left, re-queued');
        } else {
            $job = $DB->get_record('tool_imageextractor_job', ['id' => $job->id], '*', MUST_EXIST);
            $this->finalise($job);
        }
    }

    /**
     * Mark a set of items with a final status.
     *
     * @param int[] $ids
     * @param string $status
     * @param int $volume
     * @param int $now
     * @return void
     */
    protected function mark_items(array $ids, string $status, int $volume, int $now) {
        global $DB;
        if (!$ids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'item');
        $params['status'] = $status;
        $params['volume'] = $volume;
        $params['now'] = $now;
        $DB->execute(
            "UPDATE {tool_imageextractor_item}
                SET status = :status, volume = :volume, timeprocessed = :now
              WHERE id $insql",
            $params
        );
    }

    /**
     * Write the manifest and mark the job complete.
     *
     * @param \stdClass $job
     * @return void
     */
    protected function finalise(\stdClass $job) {
        global $DB;
        $this->write_manifest($job);
        $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_COMPLETED, ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'timecompleted', time(), ['id' => $job->id]);
        mtrace('tool_imageextractor: job ' . $job->id . ' completed');
    }

    /**
     * Generate the master manifest CSV for a job from its item rows.
     *
     * @param \stdClass $job
     * @return void
     */
    protected function write_manifest(\stdClass $job) {
        global $DB;

        $fs = get_file_storage();
        $context = manager::context();

        $tempdir = make_request_directory();
        $temppath = $tempdir . '/manifest.csv';
        $handle = fopen($temppath, 'w');
        fputcsv($handle, [
            'outputname', 'originalname', 'volume', 'fileid', 'contenthash',
            'filesize', 'mimetype', 'component', 'filearea', 'fileitemid',
            'contextid', 'courseid', 'courseshortname', 'cmid', 'module',
            'modulename', 'uploaderid', 'author', 'license', 'imagewidth',
            'imageheight', 'alttext', 'filetimecreated', 'status',
        ], ',', '"', '\\');

        $rs = $DB->get_recordset('tool_imageextractor_item', ['jobid' => $job->id], 'volume ASC, id ASC');
        foreach ($rs as $item) {
            $moduleinfo = $this->resolve_module((int) $item->contextid);
            fputcsv($handle, [
                $item->outputname,
                $item->filename,
                $item->volume,
                $item->fileid,
                $item->contenthash,
                $item->filesize,
                $item->mimetype,
                $item->component,
                $item->filearea,
                $item->fileitemid,
                $item->contextid,
                $item->courseid,
                $item->courseshortname,
                $moduleinfo->cmid ?: '',
                $moduleinfo->modname,
                $moduleinfo->modulename,
                $item->uploaderid,
                (string) ($item->author ?? ''),
                (string) ($item->license ?? ''),
                $item->imagewidth ?: '',
                $item->imageheight ?: '',
                (string) ($item->alttext ?? ''),
                $item->filetimecreated ? userdate($item->filetimecreated) : '',
                $item->status,
            ], ',', '"', '\\');
        }
        $rs->close();
        fclose($handle);

        $filerecord = [
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'manifest',
            'itemid'    => (int) $job->id,
            'filepath'  => '/',
            'filename'  => 'manifest.csv',
        ];
        $fs->delete_area_files($context->id, manager::COMPONENT, 'manifest', (int) $job->id);
        $fs->create_file_from_pathname($filerecord, $temppath);
    }

    /**
     * Build the per-image JSON sidecar content.
     *
     * @param \stdClass $item
     * @return string
     */
    protected function sidecar_json(\stdClass $item): string {
        $data = [
            'outputname'      => $item->outputname,
            'originalname'    => $item->filename,
            'fileid'          => (int) $item->fileid,
            'contenthash'     => $item->contenthash,
            'filesize'        => (int) $item->filesize,
            'mimetype'        => $item->mimetype,
            'component'       => $item->component,
            'filearea'        => $item->filearea,
            'fileitemid'      => (int) $item->fileitemid,
            'contextid'       => (int) $item->contextid,
            'courseid'        => (int) $item->courseid,
            'courseshortname' => $item->courseshortname,
            'cmid'            => (int) ($item->cmid ?? 0),
            'module'          => (string) ($item->modname ?? ''),
            'modulename'      => (string) ($item->modulename ?? ''),
            'uploaderid'      => (int) $item->uploaderid,
            'author'          => (string) ($item->author ?? ''),
            'license'         => (string) ($item->license ?? ''),
            'imagewidth'      => (int) ($item->imagewidth ?? 0),
            'imageheight'     => (int) ($item->imageheight ?? 0),
            'alttext'         => (string) ($item->alttext ?? ''),
            'filetimecreated' => (int) $item->filetimecreated,
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Render the output name for one matched item from the job's naming rule.
     *
     * The course a file belongs to is resolved (cached) from its stored context
     * so course placeholders keep working even though the type-agnostic match no
     * longer records course details.
     *
     * @param \stdClass $job
     * @param \stdClass $item The matched item row.
     * @param int $seq Sequence number for this file within the job.
     * @return string A cleaned output filename (not yet made unique).
     */
    protected function render_name(\stdClass $job, \stdClass $item, int $seq): string {
        $courseinfo = $this->resolve_course((int) $item->contextid);
        return naming::render($job->namingrule, [
            'originalname'    => $item->filename,
            'fileid'          => $item->fileid,
            'contenthash'     => $item->contenthash,
            'component'       => $item->component,
            'filearea'        => $item->filearea,
            'itemid'          => $item->fileitemid,
            'courseid'        => $courseinfo->courseid,
            'coursename'      => $courseinfo->fullname,
            'courseshortname' => $courseinfo->shortname,
            'uploaderid'      => $item->uploaderid,
            'mimetype'        => $item->mimetype,
            'seq'             => $seq,
            'date'            => userdate((int) $item->filetimecreated, '%Y%m%d'),
        ]);
    }

    /**
     * Resolve the description (alt text) of a matched image by reading the HTML
     * field that embeds it. Only images can carry a description, and only when
     * embedded in a mapped rich-text field; everything else is blank. When an
     * image is embedded more than once with different descriptions, they are
     * joined so the export shows every one.
     *
     * @param \stdClass $item The matched item row (mid-pack, fields populated).
     * @return string
     */
    protected function resolve_alt(\stdClass $item): string {
        if (strpos((string) $item->mimetype, 'image/') !== 0) {
            return '';
        }
        $alts = [];
        foreach (\tool_imageextractor\htmllocator::locate($item) as $location) {
            foreach (\tool_imageextractor\htmllocator::extract_alts($location->html, $item->filename) as $alt) {
                if (trim($alt) !== '' && !in_array($alt, $alts, true)) {
                    $alts[] = $alt;
                }
            }
        }
        return \core_text::substr(implode(' | ', $alts), 0, 65535);
    }

    /**
     * Resolve the activity (course module) a file's context belongs to, for
     * the export metadata: the cmid, the module type ("lesson") and the
     * instance's display name ("Lesson 1"). Files outside a module context
     * (course summaries, blocks...) resolve to blanks.
     *
     * @param int $contextid
     * @return \stdClass Object with cmid, modname and modulename.
     */
    protected function resolve_module(int $contextid): \stdClass {
        global $DB;

        if (isset($this->modulecache[$contextid])) {
            return $this->modulecache[$contextid];
        }

        $info = (object) ['cmid' => 0, 'modname' => '', 'modulename' => ''];
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if ($context && $context->contextlevel == CONTEXT_MODULE) {
            $cm = $DB->get_record_sql(
                'SELECT cm.id, cm.instance, md.name AS modname
                   FROM {course_modules} cm
                   JOIN {modules} md ON md.id = cm.module
                  WHERE cm.id = :cmid',
                ['cmid' => (int) $context->instanceid]
            );
            if ($cm) {
                $info->cmid = (int) $cm->id;
                $info->modname = $cm->modname;
                try {
                    $name = $DB->get_field($cm->modname, 'name', ['id' => $cm->instance], IGNORE_MISSING);
                    $info->modulename = is_string($name) ? $name : '';
                } catch (\dml_exception $e) {
                    // A module whose table is gone (broken uninstall) - leave
                    // the name blank rather than failing the export.
                    $info->modulename = '';
                }
            }
        }

        $this->modulecache[$contextid] = $info;
        return $info;
    }

    /**
     * Resolve the course a file's context belongs to, for naming.
     *
     * @param int $contextid
     * @return \stdClass Object with courseid, shortname and fullname.
     */
    protected function resolve_course(int $contextid): \stdClass {
        global $DB;

        if (isset($this->coursecache[$contextid])) {
            return $this->coursecache[$contextid];
        }

        $info = (object) ['courseid' => 0, 'shortname' => '', 'fullname' => ''];
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if ($context) {
            $coursecontext = $context->get_course_context(false);
            if ($coursecontext) {
                $course = $DB->get_record(
                    'course',
                    ['id' => $coursecontext->instanceid],
                    'id, shortname, fullname',
                    IGNORE_MISSING
                );
                if ($course) {
                    $info->courseid = (int) $course->id;
                    $info->shortname = $course->shortname;
                    $info->fullname = $course->fullname;
                }
            }
        }

        $this->coursecache[$contextid] = $info;
        return $info;
    }
}
