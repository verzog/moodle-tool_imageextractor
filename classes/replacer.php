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
 * Engine for replace/restore jobs.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Selects target files and swaps their content for a replacement, keeping a
 * backup of each original so the change can be restored.
 *
 * A file's content is swapped by deleting the existing file record and
 * recreating it at the same logical location (same context, component, area,
 * item id, path and name). That preserves every embedded reference and
 * pluginfile URL - only the internal file id changes.
 */
class replacer {
    /** @var \stdClass The replace job. */
    protected $job;

    /** @var \file_storage */
    protected $fs;

    /** @var \context_system */
    protected $context;

    /** @var \stored_file|null Cached single-mode replacement file. */
    protected $singlereplacement = false;

    /** @var array|null Cached zip-mode map of basename => stored_file. */
    protected $zipmap = null;

    /**
     * Constructor.
     *
     * @param \stdClass $job
     */
    public function __construct(\stdClass $job) {
        $this->job = $job;
        $this->fs = get_file_storage();
        $this->context = manager::context();
    }

    /**
     * Whether a stored file's content is missing or unreadable on disk.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function content_missing(\stored_file $file): bool {
        try {
            $handle = $file->get_content_file_handle();
            if ($handle === false) {
                return true;
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Read-only preview of what a replace run would do, for the confirmation
     * screen. Scans matched targets without changing anything: counts how many
     * would be replaced vs skipped (no matching replacement), and returns a
     * sample of rows. The scan is capped so a huge directory cannot stall the
     * confirm page.
     *
     * @param int $samplelimit Maximum sample rows to return.
     * @param int $scancap Maximum matched files to scan for the breakdown.
     * @return array ['total','scanned','willreplace','willskip','truncated','rows']
     */
    public function preview(int $samplelimit = 50, int $scancap = 2000): array {
        $matcher = new matcher(manager::decode_criteria($this->job), false);
        $estimate = $matcher->estimate();
        // Unordered: sorting the whole matched set is a heavy database
        // operation and the preview does not care about row order.
        $rs = $matcher->get_recordset(false);
        $missingonly = !empty($this->job->missingonly);

        $scanned = 0;
        $willreplace = 0;
        $willskip = 0;
        $truncated = false;
        $rows = [];
        foreach ($rs as $file) {
            if ($scanned >= $scancap) {
                $truncated = true;
                break;
            }
            // Only "missing only" jobs need the stored_file (to inspect the
            // content); fetching it is one extra query per row, and the
            // matcher's WHERE already excludes directories and empty rows.
            if ($missingonly) {
                $stored = $this->fs->get_file_by_id((int) $file->id);
                if (!$stored || !self::content_missing($stored)) {
                    continue;
                }
            }
            $scanned++;
            $replacement = $this->resolve_replacement($file->filename);
            if ($replacement) {
                $willreplace++;
            } else {
                $willskip++;
            }
            if (count($rows) < $samplelimit) {
                $rows[] = (object) [
                    'filename'    => $file->filename,
                    'component'   => $file->component,
                    'filearea'    => $file->filearea,
                    'filesize'    => (int) $file->filesize,
                    'replacement' => $replacement ? $replacement->get_filename() : null,
                ];
            }
        }
        $rs->close();

        return [
            'total'       => (int) $estimate['count'],
            'scanned'     => $scanned,
            'willreplace' => $willreplace,
            'willskip'    => $willskip,
            'truncated'   => $truncated,
            'rows'        => $rows,
        ];
    }

    /**
     * Match target files and create one item row per target, in one pass.
     *
     * Kept for the CLI and tests; the cron path uses prepare_page() so the
     * match is spread across throttled batches. This resets the running totals
     * and pages through the whole match itself.
     *
     * @return void
     */
    public function prepare() {
        $after = 0;
        do {
            $page = $this->prepare_page($after, 1000);
            $after = $page['lastid'];
        } while (!$page['exhausted']);
        manager::recount_totals((int) $this->job->id);
    }

    /**
     * Match and record one keyset page of target files, resuming after the
     * given file id. Returns the cursor to continue from, how many targets were
     * recorded, their total size, and whether the scan is exhausted.
     *
     * This only records the page's item rows; the caller sets the job totals
     * from the recorded rows once matching completes (manager::recount_totals).
     * The page first removes any items for its own file ids, so a retried run
     * cannot duplicate targets while leaving other pages' rows untouched.
     *
     * @param int $afterid Cursor file id (0 to start).
     * @param int $limit Maximum files to record this page.
     * @return array ['lastid' => int, 'matched' => int, 'scanned' => int, 'bytes' => int, 'exhausted' => bool]
     */
    public function prepare_page(int $afterid, int $limit): array {
        global $DB;

        // Unordered keyset by file id: replace targets every match, so no
        // hash-sorting is needed and each page streams straight from the index.
        $matcher = new matcher(manager::decode_criteria($this->job), false);
        $rows = $matcher->get_page($afterid, '', $limit, false);

        // Idempotency: a crashed attempt (or a forked continuation) may have
        // already inserted items for these exact files. Remove only THIS page's
        // file ids before re-recording them, so a retry never disturbs the rows
        // other pages have written (a broad "fileid > cursor" delete could wipe
        // pages a later continuation already recorded).
        if ($rows) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'pf');
            $inparams['jobid'] = (int) $this->job->id;
            $DB->delete_records_select(
                'tool_imageextractor_item',
                "jobid = :jobid AND fileid $insql",
                $inparams
            );
        }

        $missingonly = !empty($this->job->missingonly);

        $matched = 0;
        $bytes = 0;
        $batch = [];
        $lastid = $afterid;

        foreach ($rows as $file) {
            $lastid = (int) $file->id;

            // In "missing only" mode we keep just the broken files. Only that
            // mode needs the stored_file hydrated (one extra query per row);
            // the matcher's WHERE already excludes directories and empty rows.
            if ($missingonly) {
                $stored = $this->fs->get_file_by_id((int) $file->id);
                if (!$stored || !self::content_missing($stored)) {
                    continue;
                }
            }

            // The match is type-agnostic: it records the file as a pending item
            // without resolving a replacement or computing an output name. The
            // extract action names it at pack time; the replace action resolves
            // (and possibly skips) it at apply time.
            $item = new \stdClass();
            $item->jobid = (int) $this->job->id;
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
            $item->courseid = 0;
            $item->outputname = '';
            $item->replacementname = null;
            $item->note = null;
            $item->volume = 0;
            $item->status = 'pending';
            $item->timeprocessed = 0;
            $batch[] = $item;

            $matched++;
            $bytes += (int) $file->filesize;
        }
        if ($batch) {
            $DB->insert_records('tool_imageextractor_item', $batch);
        }

        return [
            'lastid'    => $lastid,
            'matched'   => $matched,
            // Rows examined this page - in "missing only" mode more than were
            // recorded. This is the progress-bar increment: the estimated total
            // counts every criteria match, whether or not it is later kept.
            'scanned'   => count($rows),
            'bytes'     => $bytes,
            'exhausted' => count($rows) < $limit,
        ];
    }

    /**
     * Replace the content of up to $limit pending targets.
     *
     * @param int $limit
     * @return int Number of targets still pending after this batch.
     */
    public function apply_batch(int $limit): int {
        global $DB;

        $items = $DB->get_records(
            'tool_imageextractor_item',
            ['jobid' => $this->job->id, 'status' => 'pending'],
            'id ASC',
            '*',
            0,
            $limit
        );

        $done = 0;
        $failed = 0;
        foreach ($items as $item) {
            // Resolve the replacement now (the type-agnostic match did not).
            // A target with no matching replacement is skipped, not failed -
            // it is an expected outcome of a ZIP that does not cover every file,
            // and it also records the resolved name for the review/manifest.
            $replacement = $this->resolve_replacement($item->filename);
            if (!$replacement) {
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'skipped',
                    'timeprocessed' => time(),
                    'note'          => get_string('noreplacement', 'tool_imageextractor'),
                ]);
                continue;
            }
            try {
                $this->replace_one($item, $replacement);
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'              => $item->id,
                    'status'          => 'done',
                    'replacementname' => $replacement->get_filename(),
                    'timeprocessed'   => time(),
                    'note'            => get_string('replaced', 'tool_imageextractor'),
                ]);
                $done++;
            } catch (\Throwable $e) {
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'failed',
                    'timeprocessed' => time(),
                    'note'          => $e->getMessage(),
                ]);
                $failed++;
                mtrace('tool_imageextractor: replace failed for item ' . $item->id . ': ' . $e->getMessage());
            }
        }

        if ($done) {
            $DB->execute(
                'UPDATE {tool_imageextractor_job} SET processedcount = processedcount + :c WHERE id = :id',
                ['c' => $done, 'id' => $this->job->id]
            );
        }
        if ($failed) {
            $DB->execute(
                'UPDATE {tool_imageextractor_job} SET failedcount = failedcount + :c WHERE id = :id',
                ['c' => $failed, 'id' => $this->job->id]
            );
        }

        return $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $this->job->id, 'status' => 'pending']
        );
    }

    /**
     * Restore up to $limit replaced targets from their backups.
     *
     * @param int $limit
     * @return int Number of restorable targets still remaining.
     */
    public function restore_batch(int $limit): int {
        global $DB;

        $items = $DB->get_records(
            'tool_imageextractor_item',
            ['jobid' => $this->job->id, 'status' => 'done'],
            'id ASC',
            '*',
            0,
            $limit
        );

        foreach ($items as $item) {
            $backup = $this->fs->get_file(
                $this->context->id,
                manager::COMPONENT,
                'backup',
                (int) $item->id,
                '/',
                $item->filename
            );
            if (!$backup) {
                $DB->set_field('tool_imageextractor_item', 'status', 'restorefailed', ['id' => $item->id]);
                $DB->set_field(
                    'tool_imageextractor_item',
                    'note',
                    get_string('nobackup', 'tool_imageextractor'),
                    ['id' => $item->id]
                );
                continue;
            }
            try {
                $this->write_to_location($item, $backup);
                $this->fs->delete_area_files($this->context->id, manager::COMPONENT, 'backup', (int) $item->id);
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'restored',
                    'timeprocessed' => time(),
                    'note'          => get_string('restored', 'tool_imageextractor'),
                ]);
            } catch (\Throwable $e) {
                $DB->set_field('tool_imageextractor_item', 'status', 'restorefailed', ['id' => $item->id]);
                $DB->set_field('tool_imageextractor_item', 'note', $e->getMessage(), ['id' => $item->id]);
                mtrace('tool_imageextractor: restore failed for item ' . $item->id . ': ' . $e->getMessage());
            }
        }

        return $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $this->job->id, 'status' => 'done']
        );
    }

    /**
     * Back up and replace one target's content.
     *
     * @param \stdClass $item
     * @param \stored_file $replacement The resolved replacement source.
     * @return void
     */
    protected function replace_one(\stdClass $item, \stored_file $replacement) {
        $existing = $this->fs->get_file(
            $item->contextid,
            $item->component,
            $item->filearea,
            (int) $item->fileitemid,
            $item->filepath,
            $item->filename
        );

        // Back up the original content first, when present and requested.
        if ($this->job->backup && $existing && !self::content_missing($existing)) {
            $this->fs->delete_area_files($this->context->id, manager::COMPONENT, 'backup', (int) $item->id);
            $this->fs->create_file_from_storedfile([
                'contextid' => $this->context->id,
                'component' => manager::COMPONENT,
                'filearea'  => 'backup',
                'itemid'    => (int) $item->id,
                'filepath'  => '/',
                'filename'  => $item->filename,
            ], $existing);
        }

        $this->write_to_location($item, $replacement);
    }

    /**
     * Write a source file's content into a target item's location, replacing
     * whatever is there.
     *
     * @param \stdClass $item
     * @param \stored_file $source
     * @return void
     */
    protected function write_to_location(\stdClass $item, \stored_file $source) {
        $existing = $this->fs->get_file(
            $item->contextid,
            $item->component,
            $item->filearea,
            (int) $item->fileitemid,
            $item->filepath,
            $item->filename
        );
        if ($existing) {
            $existing->delete();
        }
        $this->fs->create_file_from_storedfile([
            'contextid' => (int) $item->contextid,
            'component' => $item->component,
            'filearea'  => $item->filearea,
            'itemid'    => (int) $item->fileitemid,
            'filepath'  => $item->filepath,
            'filename'  => $item->filename,
        ], $source);
    }

    /**
     * The stored replacement file that would be applied to a given target
     * filename, or null when none matches. Public wrapper over the same
     * resolution the apply path uses, so the review preview can show (and link
     * to) the exact file that will be written - including its real filepath
     * when a ZIP replacement stored it inside a folder.
     *
     * @param string $targetfilename
     * @return \stored_file|null
     */
    public function replacement_for(string $targetfilename): ?\stored_file {
        return $this->resolve_replacement($targetfilename);
    }

    /**
     * Find the replacement source for a target filename.
     *
     * In single mode the one uploaded replacement is used for every target;
     * in zip mode the replacement is the uploaded entry whose name matches the
     * target's filename.
     *
     * @param string $targetfilename
     * @return \stored_file|null
     */
    protected function resolve_replacement(string $targetfilename): ?\stored_file {
        if ($this->job->replacemode === 'single') {
            if ($this->singlereplacement === false) {
                $this->singlereplacement = null;
                $files = $this->fs->get_area_files(
                    $this->context->id,
                    manager::COMPONENT,
                    'replacement',
                    (int) $this->job->id,
                    'filename',
                    false
                );
                foreach ($files as $file) {
                    $this->singlereplacement = $file;
                    break;
                }
            }
            return $this->singlereplacement;
        }

        // Zip mode: match by filename, ignoring any folder structure in the
        // uploaded archive.
        if ($this->zipmap === null) {
            $this->zipmap = [];
            $files = $this->fs->get_area_files(
                $this->context->id,
                manager::COMPONENT,
                'replacement',
                (int) $this->job->id,
                'filepath, filename',
                false
            );
            foreach ($files as $file) {
                $this->zipmap[$file->get_filename()] = $file;
            }
        }
        return $this->zipmap[$targetfilename] ?? null;
    }
}
