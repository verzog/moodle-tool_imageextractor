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

    /** @var array|null Cached alt-text map of filename => description. */
    protected $altmap = null;

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
            // Apply the post-match refinements (missing content / missing alt);
            // each only queries when its refinement is switched on.
            if (!$this->passes_refinements($file)) {
                continue;
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
     * Whether a matched file passes the job's post-match refinements, which the
     * matcher's SQL cannot express: "missing only" (content gone/unreadable)
     * and "missing alt" (embedded in content with an empty/missing description).
     * A job with neither refinement passes every file without any extra query.
     *
     * @param \stdClass $file A matched {files} row from the matcher.
     * @return bool
     */
    protected function passes_refinements(\stdClass $file): bool {
        if (!empty($this->job->missingonly)) {
            $stored = $this->fs->get_file_by_id((int) $file->id);
            if (!$stored || !self::content_missing($stored)) {
                return false;
            }
        }
        if (!empty($this->job->altmissing)) {
            $ref = (object) [
                'component'  => $file->component,
                'filearea'   => $file->filearea,
                'contextid'  => (int) $file->contextid,
                'fileitemid' => (int) $file->itemid,
                'filename'   => $file->filename,
            ];
            if (!htmllocator::is_undescribed($ref)) {
                return false;
            }
        }
        return true;
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

        $matched = 0;
        $bytes = 0;
        $batch = [];
        $lastid = $afterid;

        foreach ($rows as $file) {
            $lastid = (int) $file->id;

            // Keep only files passing the post-match refinements (missing
            // content / missing alt); each queries only when switched on, and
            // the matcher's WHERE already excludes directories and empty rows.
            if (!$this->passes_refinements($file)) {
                continue;
            }

            // The match is type-agnostic: it records the file as a pending item
            // without resolving a course, replacement or computing an output
            // name. The extract action names it at pack time; the replace
            // action resolves (and possibly skips) it at apply time.
            $item = manager::item_from_file((int) $this->job->id, $file);
            $item->courseid = 0;
            $item->outputname = '';
            $item->replacementname = null;
            $item->note = null;
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

        // Metadata-only jobs never touch content; they stamp the new author
        // and/or license onto each matched file instead.
        if ($this->job->replacemode === 'metadata') {
            return $this->apply_metadata_batch($limit);
        }

        // Alt-text jobs never touch content either; they rewrite the image's
        // description in the HTML that embeds it.
        if ($this->job->replacemode === 'alttext') {
            return $this->apply_alttext_batch($limit);
        }

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
     * Metadata-only apply: set the job's new author and/or license on up to
     * $limit pending targets, without touching their content. Nothing is
     * backed up because nothing destructive happens to the image data; the
     * per-item note records the old values for reference.
     *
     * @param int $limit
     * @return int Number of targets still pending after this batch.
     */
    protected function apply_metadata_batch(int $limit): int {
        global $DB;

        $newauthor = trim((string) ($this->job->metaauthor ?? ''));
        $newlicense = trim((string) ($this->job->metalicense ?? ''));

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
            $stored = $this->fs->get_file(
                $item->contextid,
                $item->component,
                $item->filearea,
                (int) $item->fileitemid,
                $item->filepath,
                $item->filename
            );
            if (!$stored) {
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'skipped',
                    'timeprocessed' => time(),
                    'note'          => get_string('targetmissing', 'tool_imageextractor'),
                ]);
                continue;
            }
            try {
                $notes = [];
                if ($newauthor !== '') {
                    $notes[] = 'author: ' . (string) $stored->get_author() . ' -> ' . $newauthor;
                    $stored->set_author($newauthor);
                }
                if ($newlicense !== '') {
                    $notes[] = 'license: ' . (string) $stored->get_license() . ' -> ' . $newlicense;
                    $stored->set_license($newlicense);
                }
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'done',
                    'timeprocessed' => time(),
                    'note'          => \core_text::substr(implode('; ', $notes), 0, 255),
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
                mtrace('tool_imageextractor: metadata update failed for item ' . $item->id . ': ' . $e->getMessage());
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
     * Alt-text apply: rewrite the description of up to $limit pending targets
     * in the HTML that embeds them, using the job's uploaded filename->alt CSV.
     * Content is never touched. A file with no CSV entry, or that is not
     * embedded in a mapped HTML field, is skipped; the previous alt text is
     * recorded in the item note so the change is auditable.
     *
     * @param int $limit
     * @return int Number of targets still pending after this batch.
     */
    protected function apply_alttext_batch(int $limit): int {
        global $DB;

        $map = $this->alt_map();

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
            // The CSV is keyed by file name; a target the admin did not list is
            // left untouched (an expected outcome, not a failure).
            if (!array_key_exists($item->filename, $map)) {
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'skipped',
                    'timeprocessed' => time(),
                    'note'          => get_string('altnomapping', 'tool_imageextractor'),
                ]);
                continue;
            }
            $newalt = $map[$item->filename];
            try {
                $locations = htmllocator::locate($item);
                if (!$locations) {
                    $DB->update_record('tool_imageextractor_item', (object) [
                        'id'            => $item->id,
                        'status'        => 'skipped',
                        'timeprocessed' => time(),
                        'note'          => get_string('altnotembedded', 'tool_imageextractor'),
                    ]);
                    continue;
                }
                $oldalts = [];
                $changed = 0;
                foreach ($locations as $location) {
                    foreach (htmllocator::extract_alts($location->html, $item->filename) as $old) {
                        $oldalts[] = $old;
                    }
                    [$newhtml, $n] = htmllocator::set_alt($location->html, $item->filename, $newalt);
                    if ($n > 0 && $newhtml !== $location->html) {
                        // Back up the field's original HTML (once per job) so
                        // the whole change can be reverted with Restore.
                        if (!empty($this->job->backup)) {
                            $this->backup_html_field(
                                $location->table,
                                $location->column,
                                $location->id,
                                $location->html
                            );
                        }
                        $DB->set_field($location->table, $location->column, $newhtml, ['id' => $location->id]);
                        $changed += $n;
                    }
                }
                if ($changed === 0) {
                    $DB->update_record('tool_imageextractor_item', (object) [
                        'id'            => $item->id,
                        'status'        => 'skipped',
                        'timeprocessed' => time(),
                        'note'          => get_string('altnotembedded', 'tool_imageextractor'),
                    ]);
                    continue;
                }
                $prevdesc = \core_text::substr(implode(' | ', array_unique($oldalts)), 0, 200);
                $oldnote = get_string('altwas', 'tool_imageextractor', $prevdesc);
                $DB->update_record('tool_imageextractor_item', (object) [
                    'id'            => $item->id,
                    'status'        => 'done',
                    'timeprocessed' => time(),
                    'note'          => \core_text::substr($oldnote, 0, 255),
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
                mtrace('tool_imageextractor: alt-text update failed for item ' . $item->id . ': ' . $e->getMessage());
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
     * The description the alt-text CSV would write for a target file name, or
     * null when the CSV does not list it. Public wrapper over the same map the
     * apply uses, so the confirmation preview can show the planned change.
     *
     * @param string $filename
     * @return string|null
     */
    public function planned_alt(string $filename): ?string {
        $map = $this->alt_map();
        return array_key_exists($filename, $map) ? $map[$filename] : null;
    }

    /**
     * Store a content field's original HTML once per job, so an alt-text
     * replace can be reverted field-for-field. A later item that changes the
     * same field finds the backup already present (unique per job+field) and
     * leaves the original untouched.
     *
     * @param string $table
     * @param string $column
     * @param int $rowid
     * @param string $original The field's HTML before this job changed it.
     * @return void
     */
    protected function backup_html_field(string $table, string $column, int $rowid, string $original): void {
        global $DB;
        $key = [
            'jobid'      => (int) $this->job->id,
            'tablename'  => $table,
            'columnname' => $column,
            'rowid'      => $rowid,
        ];
        if ($DB->record_exists('tool_imageextractor_htmlbackup', $key)) {
            return;
        }
        $record = (object) $key;
        $record->oldcontent = $original;
        $record->timecreated = time();
        try {
            $DB->insert_record('tool_imageextractor_htmlbackup', $record);
        } catch (\dml_exception $e) {
            // A concurrent batch backed up the same field first; the unique
            // (jobid, table, column, row) index rejected this duplicate, which
            // is exactly the intended outcome.
            unset($e);
        }
    }

    /**
     * Revert an alt-text replace: write each changed field's backed-up original
     * HTML back, a bounded batch per run, then flip the job's items to restored
     * once every field is back.
     *
     * @param int $limit
     * @return int Number of field backups still to restore.
     */
    protected function restore_alt_batch(int $limit): int {
        global $DB;

        $backups = $DB->get_records(
            'tool_imageextractor_htmlbackup',
            ['jobid' => $this->job->id],
            'id ASC',
            '*',
            0,
            $limit
        );
        foreach ($backups as $backup) {
            try {
                $DB->set_field($backup->tablename, $backup->columnname, $backup->oldcontent, ['id' => $backup->rowid]);
            } catch (\Throwable $e) {
                mtrace('tool_imageextractor: alt-text restore failed for ' .
                    $backup->tablename . '.' . $backup->columnname . ' #' . $backup->rowid . ': ' . $e->getMessage());
            }
            $DB->delete_records('tool_imageextractor_htmlbackup', ['id' => $backup->id]);
        }

        $remaining = $DB->count_records('tool_imageextractor_htmlbackup', ['jobid' => $this->job->id]);
        if ($remaining === 0) {
            // Every field is back to its original; mark the changed items
            // restored so the job reads as reverted.
            $DB->set_field_select(
                'tool_imageextractor_item',
                'status',
                'restored',
                'jobid = :jobid AND status = :done',
                ['jobid' => $this->job->id, 'done' => 'done']
            );
        }
        return $remaining;
    }

    /**
     * Load and cache the job's alt-text map (file name => description) from its
     * uploaded CSV. The CSV needs a "filename" column and an "alttext" column;
     * the exported manifest already carries both, so an edited manifest drops
     * straight in. A row with a blank alttext maps to '' (clear the alt), which
     * is deliberately distinct from a file the CSV never lists (left untouched).
     *
     * @return array
     */
    protected function alt_map(): array {
        if ($this->altmap !== null) {
            return $this->altmap;
        }
        $this->altmap = [];
        $files = $this->fs->get_area_files(
            $this->context->id,
            manager::COMPONENT,
            'altcsv',
            (int) $this->job->id,
            'id',
            false
        );
        $csv = null;
        foreach ($files as $file) {
            $csv = $file;
            break;
        }
        if (!$csv) {
            return $this->altmap;
        }
        foreach (csv_importer::parse_rows($csv->get_content()) as $row) {
            $filename = trim((string) ($row['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $this->altmap[$filename] = (string) ($row['alttext'] ?? '');
        }
        return $this->altmap;
    }

    /**
     * Optimize one keyset page of the job's stored replacement images: cap the
     * longest edge at the job's optimizemaxpx and re-encode JPEG/WebP at the
     * job's optimizequality. Each stored file is rewritten in place (same
     * location, same name) so filename matching is unaffected.
     *
     * The cursor is the pathnamehash of the last file processed: rewriting a
     * file gives it a new id but the same pathnamehash, so id-based paging
     * would revisit rewritten files while hash-based paging cannot.
     *
     * @param string $afterhash Cursor pathnamehash ('' to start).
     * @param int $limit Maximum files to examine this page.
     * @return array ['lasthash' => string, 'processed' => int, 'exhausted' => bool]
     */
    public function optimize_page(string $afterhash, int $limit): array {
        global $DB;

        $maxpx = (int) $this->job->optimizemaxpx;
        $quality = min(100, max(1, (int) $this->job->optimizequality));

        $rows = $DB->get_records_select(
            'files',
            "contextid = :ctx AND component = :comp AND filearea = 'replacement'
                AND itemid = :jobid AND filename <> '.' AND pathnamehash > :cursor",
            [
                'ctx'    => $this->context->id,
                'comp'   => manager::COMPONENT,
                'jobid'  => (int) $this->job->id,
                'cursor' => $afterhash,
            ],
            'pathnamehash ASC',
            'id, pathnamehash',
            0,
            $limit
        );

        $processed = 0;
        $lasthash = $afterhash;
        foreach ($rows as $row) {
            $lasthash = $row->pathnamehash;
            $processed++;
            $stored = $this->fs->get_file_by_id((int) $row->id);
            if (!$stored || $stored->is_directory()) {
                continue;
            }
            try {
                $this->optimize_one($stored, $maxpx, $quality);
            } catch (\Throwable $e) {
                // A corrupt or unsupported image is left as uploaded rather
                // than failing the job; the apply will use it unoptimized.
                mtrace('tool_imageextractor: could not optimize replacement "'
                    . $stored->get_filename() . '": ' . $e->getMessage());
            }
        }

        return [
            'lasthash'  => $lasthash,
            'processed' => $processed,
            'exhausted' => count($rows) < $limit,
        ];
    }

    /**
     * Optimize a single stored replacement image in place, when worthwhile.
     *
     * GIFs are skipped (re-encoding drops animation frames) and so is any
     * content GD cannot decode (SVG and friends). The rewritten file is only
     * kept when it is actually smaller or was resized; a re-encode that grows
     * the file is discarded.
     *
     * @param \stored_file $stored
     * @param int $maxpx Longest-edge cap in pixels.
     * @param int $quality JPEG/WebP quality (1-100).
     * @return bool Whether the file was rewritten.
     */
    protected function optimize_one(\stored_file $stored, int $maxpx, int $quality): bool {
        // Only formats GD re-encodes faithfully; GIF is excluded because
        // re-encoding drops animation frames, and everything else (SVG, BMP,
        // TIFF...) is left exactly as uploaded.
        $mimetype = (string) $stored->get_mimetype();
        $supported = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimetype, $supported, true)) {
            return false;
        }
        if ($mimetype === 'image/webp' && !function_exists('imagewebp')) {
            return false;
        }

        $content = $stored->get_content();
        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        $resized = false;
        if ($maxpx > 0 && $longest > $maxpx) {
            $scale = $maxpx / $longest;
            $newwidth = max(1, (int) round($width * $scale));
            $newheight = max(1, (int) round($height * $scale));
            $scaled = imagescale($image, $newwidth, $newheight, IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
                $resized = true;
            }
        }

        // Re-encode in the original format so the filename and mime type stay
        // truthful.
        ob_start();
        if ($mimetype === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 9);
        } else if ($mimetype === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagewebp($image, null, $quality);
        } else {
            imagejpeg($image, null, $quality);
        }
        $newcontent = ob_get_clean();
        imagedestroy($image);

        if ($newcontent === false || $newcontent === '') {
            return false;
        }
        // Keep the rewrite only when it helps: it was resized, or the
        // re-encode alone made it smaller.
        if (!$resized && strlen($newcontent) >= strlen($content)) {
            return false;
        }

        $filerecord = [
            'contextid' => $stored->get_contextid(),
            'component' => $stored->get_component(),
            'filearea'  => $stored->get_filearea(),
            'itemid'    => $stored->get_itemid(),
            'filepath'  => $stored->get_filepath(),
            'filename'  => $stored->get_filename(),
            'mimetype'  => $mimetype,
            'author'    => $stored->get_author(),
            'license'   => $stored->get_license(),
        ];
        $stored->delete();
        $this->fs->create_file_from_string($filerecord, $newcontent);
        return true;
    }

    /**
     * How many files are stored in this job's replacement area (the
     * denominator for the optimization progress bar).
     *
     * @return int
     */
    public function count_replacements(): int {
        global $DB;
        return $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = :comp AND filearea = 'replacement'
                AND itemid = :jobid AND filename <> '.'",
            [
                'ctx'   => $this->context->id,
                'comp'  => manager::COMPONENT,
                'jobid' => (int) $this->job->id,
            ]
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

        // Alt-text replaces are reverted from the per-field HTML backups, not
        // from per-file content backups.
        if ($this->job->replacemode === 'alttext') {
            return $this->restore_alt_batch($limit);
        }

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
                // Restore carries the backup's own metadata back to the
                // location, not the replacement's that currently sits there.
                $this->write_to_location($item, $backup, true);
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
     * The metadata (author, license, uploader, creation time) written onto the
     * new file depends on which direction we are going:
     *
     * - A content replace preserves the TARGET's own metadata: it describes the
     *   file at its location, not the uploaded replacement, so credit lines and
     *   date-based criteria keep working after the swap.
     * - A restore puts back the BACKUP's metadata, because the backup is a
     *   faithful copy of the original captured at replace time. Preserving the
     *   live (replaced) file's metadata would instead strand the replacement's
     *   author/license on the restored original - visibly wrong for a job that
     *   was replaced before content-preservation existed.
     *
     * When the chosen source of metadata is absent, fall back to what the
     * analysis recorded on the item row.
     *
     * @param \stdClass $item
     * @param \stored_file $source The file whose content is written.
     * @param bool $metadatafromsource Take metadata from $source (restore)
     *                                 rather than the existing target (replace).
     * @return void
     */
    protected function write_to_location(\stdClass $item, \stored_file $source, bool $metadatafromsource = false) {
        $existing = $this->fs->get_file(
            $item->contextid,
            $item->component,
            $item->filearea,
            (int) $item->fileitemid,
            $item->filepath,
            $item->filename
        );

        $meta = $metadatafromsource ? $source : $existing;
        $filerecord = [
            'contextid'   => (int) $item->contextid,
            'component'   => $item->component,
            'filearea'    => $item->filearea,
            'itemid'      => (int) $item->fileitemid,
            'filepath'    => $item->filepath,
            'filename'    => $item->filename,
            'author'      => $meta ? $meta->get_author() : ($item->author ?? null),
            'license'     => $meta ? $meta->get_license() : ($item->license ?? null),
            'userid'      => $meta ? $meta->get_userid() : ((int) $item->uploaderid ?: null),
            'timecreated' => $meta ? $meta->get_timecreated() : (int) $item->filetimecreated,
        ];

        if ($existing) {
            $existing->delete();
        }
        $this->fs->create_file_from_storedfile($filerecord, $source);
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
