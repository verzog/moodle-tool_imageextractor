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
                // Clear any stale results here (deferred off the web request)
                // before matching, so a re-run starts from a clean slate
                // without duplicating items.
                if ($clearfirst) {
                    manager::clear_results($jobid);
                }
                $this->prepare($job);
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

        $rs = $DB->get_recordset(
            'tool_imageextractor_item',
            ['jobid' => $job->id, 'status' => 'pending'],
            'id ASC'
        );
        foreach ($rs as $item) {
            $stored = $fs->get_file_by_id((int) $item->fileid);
            // Skip files that have vanished or whose content is missing on disk -
            // the ZIP packer would otherwise silently drop them and the manifest
            // would wrongly report a successful export.
            if (!$stored || $stored->is_directory() || \tool_imageextractor\replacer::content_missing($stored)) {
                $failedids[] = (int) $item->id;
                continue;
            }

            $entry = 'images/' . $item->outputname;
            $archivefiles[$entry] = $stored;
            $archivefiles[$entry . '.json'] = [$this->sidecar_json($item)];

            $doneids[] = (int) $item->id;
            $volumebytes += (int) $item->filesize;

            // One file per volume minimum, then stop once the cap is reached.
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
            'contextid', 'courseid', 'courseshortname', 'uploaderid',
            'filetimecreated', 'status',
        ]);

        $rs = $DB->get_recordset('tool_imageextractor_item', ['jobid' => $job->id], 'volume ASC, id ASC');
        foreach ($rs as $item) {
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
                $item->uploaderid,
                $item->filetimecreated ? userdate($item->filetimecreated) : '',
                $item->status,
            ]);
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
            'uploaderid'      => (int) $item->uploaderid,
            'filetimecreated' => (int) $item->filetimecreated,
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
