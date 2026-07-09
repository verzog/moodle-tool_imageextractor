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
 * Central CRUD and orchestration for extraction jobs.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Creates, queues, queries and deletes extraction jobs, and owns the file
 * areas (source CSV, generated ZIP volumes, manifest) used by a job.
 */
class manager {
    /** @var string A saved but not yet run job. */
    const STATUS_DRAFT = 'draft';

    /** @var string Queued for the adhoc processor. */
    const STATUS_QUEUED = 'queued';

    /** @var string Currently being processed (matching or packing). */
    const STATUS_PROCESSING = 'processing';

    /** @var string Replace job analysed; awaiting admin review before applying. */
    const STATUS_REVIEW = 'review';

    /** @var string A prior run's results are being removed in the background. */
    const STATUS_CLEARING = 'clearing';

    /** @var string Finished successfully. */
    const STATUS_COMPLETED = 'completed';

    /** @var string Stopped by an error. */
    const STATUS_FAILED = 'failed';

    /** @var string Plugin component name, used for capabilities and file areas. */
    const COMPONENT = 'tool_imageextractor';

    /**
     * The system context, where every job and its files live.
     *
     * @return \context_system
     */
    public static function context(): \context_system {
        return \context_system::instance();
    }

    /**
     * Whether the plugin kill-switch permits jobs to run.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('tool_imageextractor', 'enabled');
    }

    /**
     * Whether destructive replace/restore jobs are permitted.
     *
     * @return bool
     */
    public static function is_replace_allowed(): bool {
        return (bool) get_config('tool_imageextractor', 'allow_replace');
    }

    /**
     * How many files a background task processes per batch before re-queuing.
     * Keeping each batch small bounds the burst of database work so a running
     * job cannot saturate a shared database.
     *
     * @return int At least 1.
     */
    public static function batch_size(): int {
        $configured = (int) get_config('tool_imageextractor', 'batch_size');
        return $configured > 0 ? $configured : 50;
    }

    /**
     * Seconds to leave the database idle between batches. Re-queuing the next
     * batch this far in the future (instead of looping straight on) gives
     * ordinary page requests room to run while a large job processes. A value
     * of 0 means process batches back-to-back.
     *
     * @return int Zero or more.
     */
    public static function throttle_delay(): int {
        // Distinguish "never configured" (apply the gentle default) from an
        // explicit 0 (admin opted out of throttling), since get_config returns
        // false only for the former.
        $configured = get_config('tool_imageextractor', 'throttle_delay');
        if ($configured === false || $configured === '') {
            return 20;
        }
        return max(0, (int) $configured);
    }

    /**
     * A blank criteria set with sensible defaults.
     *
     * @return array
     */
    public static function default_criteria(): array {
        return [
            'imageonly'       => true,
            'component'       => '',
            'filearea'        => '',
            'filenamepattern' => '',
            'mimetypes'       => [],
            'courseids'       => [],
            'categoryids'     => [],
            'minsize'         => 0,
            'maxsize'         => 0,
            'datefrom'        => 0,
            'dateto'          => 0,
        ];
    }

    /**
     * Build the criteria array from submitted form fields, without touching the
     * database or any uploaded CSV. Shared by save_job (which then folds in CSV
     * refinement) and the form's "Estimate" preview (which does not).
     *
     * @param \stdClass $data Submitted form data.
     * @return array Criteria in the shape of default_criteria().
     */
    public static function criteria_from_data(\stdClass $data): array {
        $criteria = self::default_criteria();
        $criteria['imageonly'] = !empty($data->imageonly);
        $criteria['component'] = trim((string) ($data->component ?? ''));
        $criteria['filearea'] = trim((string) ($data->filearea ?? ''));
        $criteria['filenamepattern'] = trim((string) ($data->filenamepattern ?? ''));
        $criteria['minsize'] = !empty($data->minsizekb) ? (int) $data->minsizekb * 1024 : 0;
        $criteria['maxsize'] = !empty($data->maxsizekb) ? (int) $data->maxsizekb * 1024 : 0;
        $criteria['datefrom'] = !empty($data->datefrom) ? (int) $data->datefrom : 0;
        $criteria['dateto'] = !empty($data->dateto) ? (int) $data->dateto : 0;

        $mimetypes = trim((string) ($data->mimetypes ?? ''));
        if ($mimetypes !== '') {
            $criteria['mimetypes'] = array_values(array_filter(array_map('trim', explode(',', $mimetypes)), 'strlen'));
        }

        $criteria['courseids'] = self::clean_ids($data->courseids ?? null);
        $criteria['categoryids'] = self::clean_ids($data->categoryids ?? null);

        return $criteria;
    }

    /**
     * Normalise a submitted list of ids: cast to int, drop 0/negatives and
     * duplicates, and reindex.
     *
     * @param mixed $raw Array of ids, or anything else (treated as empty).
     * @return int[]
     */
    protected static function clean_ids($raw): array {
        if (empty($raw) || !is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw), fn($id) => $id > 0)));
    }

    /**
     * Load one job.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get_job(int $id): \stdClass {
        global $DB;
        return $DB->get_record('tool_imageextractor_job', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Load all jobs, newest first.
     *
     * @return \stdClass[]
     */
    public static function get_jobs(): array {
        global $DB;
        return $DB->get_records('tool_imageextractor_job', null, 'timecreated DESC');
    }

    /**
     * Decode a job's stored criteria JSON.
     *
     * @param \stdClass $job
     * @return array
     */
    public static function decode_criteria(\stdClass $job): array {
        $decoded = json_decode((string) $job->criteria, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Estimate the result set for a job without running it.
     *
     * @param \stdClass $job
     * @return array ['count' => int, 'bytes' => int]
     */
    public static function estimate(\stdClass $job): array {
        $matcher = new matcher(self::decode_criteria($job), (bool) $job->dedupe);
        return $matcher->estimate();
    }

    /**
     * Create or update a job from submitted form data.
     *
     * Reads the optional CSV upload from the form's draft area, parses it
     * according to the chosen mode, and folds the result into the stored
     * criteria. Returns the job id and any CSV warnings.
     *
     * @param \stdClass $data Validated form data.
     * @return array ['id' => int, 'warnings' => string[]]
     */
    public static function save_job(\stdClass $data): array {
        global $DB, $USER;

        $now = time();
        $context = self::context();

        $criteria = self::criteria_from_data($data);
        // Remember the form's own course scope so it can be unioned back in
        // after any CSV scope list is merged below.
        $formcourseids = $criteria['courseids'];

        $csvmode = $data->csvmode ?? 'none';
        $warnings = [];
        if ($csvmode !== 'none' && !empty($data->csvfile)) {
            $content = self::read_draft_csv((int) $data->csvfile);
            if ($content !== null) {
                $rows = csv_importer::parse_rows($content);
                $result = csv_importer::to_criteria($rows, $csvmode);
                $nominated = !empty($result['criteria']['filenames'])
                    || !empty($result['criteria']['contenthashes']);
                if ($csvmode === 'match' && $nominated) {
                    // A match list nominates exact files: the criteria fields
                    // are hidden on the form and ignored here, so a stale
                    // course or MIME filter can never silently exclude a
                    // nominated file. imageonly is switched off for the same
                    // reason - the list is authoritative.
                    $criteria = self::default_criteria();
                    $criteria['imageonly'] = false;
                    $formcourseids = [];
                }
                $criteria = array_merge($criteria, $result['criteria']);
                $warnings = $result['warnings'];
            }
        }

        // The CSV merge above may have supplied its own course scope (scope
        // mode). Combine it with the form's selection so both are honoured as a
        // union rather than one silently overwriting the other.
        if ($formcourseids) {
            $csvcourseids = (!empty($criteria['courseids']) && is_array($criteria['courseids']))
                ? array_map('intval', $criteria['courseids']) : [];
            $criteria['courseids'] = array_values(array_unique(array_merge($csvcourseids, $formcourseids)));
        }

        $defaultvolmb = (int) get_config('tool_imageextractor', 'default_volume_mb');
        $volumemb = !empty($data->volumemb) ? (int) $data->volumemb : ($defaultvolmb ?: 2048);

        $jobtype = ($data->jobtype ?? 'extract') === 'replace' ? 'replace' : 'extract';

        $record = new \stdClass();
        $record->name = trim((string) $data->name);
        $record->description = trim((string) ($data->description ?? ''));
        $record->jobtype = $jobtype;
        $record->csvmode = $csvmode;
        $record->namingrule = trim((string) ($data->namingrule ?? '')) ?: '{originalname}';
        $record->dedupe = !empty($data->dedupe) ? 1 : 0;
        $record->volumesize = max(1, $volumemb) * 1024 * 1024;
        $record->replacemode = ($data->replacemode ?? 'single') === 'zip' ? 'zip' : 'single';
        $record->backup = !empty($data->backup) ? 1 : 0;
        $record->missingonly = !empty($data->missingonly) ? 1 : 0;
        $record->criteria = json_encode($criteria);
        $record->usermodified = $USER->id;
        $record->timemodified = $now;

        if (!empty($data->id)) {
            $record->id = (int) $data->id;
            $existing = self::get_job($record->id);
            $running = in_array($existing->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
            $DB->update_record('tool_imageextractor_job', $record);

            // Editing the definition invalidates any previous run's results, so
            // clear them and return the job to draft - otherwise the view page
            // would keep offering stale downloads from the old definition. We
            // skip this while a run is in flight, and for replace jobs that
            // still hold restorable backups (those must be restored or cleared
            // explicitly so originals are never silently discarded). A large
            // result set is removed in the background (reset_results defers it)
            // so saving the form cannot time out at the gateway.
            if (!$running && !self::has_restorable($record->id)) {
                self::reset_results($record->id);
            }
        } else {
            $record->status = self::STATUS_DRAFT;
            $record->timecreated = $now;
            $record->id = $DB->insert_record('tool_imageextractor_job', $record);
        }

        // Keep the uploaded CSV with the job for later reference.
        if (isset($data->csvfile)) {
            file_save_draft_area_files(
                (int) $data->csvfile,
                $context->id,
                self::COMPONENT,
                'csv',
                $record->id,
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        }

        if ($jobtype === 'replace') {
            self::store_replacement_files($data, (int) $record->id);
        }

        return ['id' => (int) $record->id, 'warnings' => $warnings];
    }

    /**
     * Store the replacement source(s) for a replace job: either the single
     * uploaded image, or the entries of an uploaded ZIP, in the job's
     * 'replacement' file area.
     *
     * @param \stdClass $data Form data.
     * @param int $jobid
     * @return void
     */
    protected static function store_replacement_files(\stdClass $data, int $jobid): void {
        global $USER;

        $context = self::context();
        $fs = get_file_storage();

        if (($data->replacemode ?? 'single') === 'zip') {
            if (empty($data->replacementzip)) {
                return;
            }
            // Read the uploaded ZIP from the draft area and unpack its entries
            // into the replacement area so they can be matched by filename.
            $usercontext = \context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files(
                $usercontext->id,
                'user',
                'draft',
                (int) $data->replacementzip,
                'id',
                false
            );
            $zip = null;
            foreach ($draftfiles as $file) {
                $zip = $file;
                break;
            }
            if (!$zip) {
                return;
            }
            $fs->delete_area_files($context->id, self::COMPONENT, 'replacement', $jobid);
            $packer = get_file_packer('application/zip');
            $packer->extract_to_storage($zip, $context->id, self::COMPONENT, 'replacement', $jobid, '/');
            return;
        }

        // Single mode: keep just the one uploaded replacement image.
        if (isset($data->replacementfile)) {
            file_save_draft_area_files(
                (int) $data->replacementfile,
                $context->id,
                self::COMPONENT,
                'replacement',
                $jobid,
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        }
    }

    /**
     * Read the (single) CSV file from a draft area as a string.
     *
     * @param int $draftitemid
     * @return string|null Null if no file was uploaded.
     */
    protected static function read_draft_csv(int $draftitemid): ?string {
        global $USER;
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        foreach ($files as $file) {
            return $file->get_content();
        }
        return null;
    }

    /**
     * Queue a job for background processing.
     *
     * @param int $jobid
     * @return void
     */
    public static function queue_job(int $jobid): void {
        global $DB;

        $job = self::get_job($jobid);
        // Re-running a replace job clears results, which would discard the
        // backups of the previous run's originals. Refuse until they have been
        // restored or explicitly cleared, so originals can never be stranded.
        if ($job->jobtype === 'replace' && self::has_restorable($jobid)) {
            throw new \moodle_exception('cannotrerunwithbackups', 'tool_imageextractor');
        }

        if ($job->jobtype === 'replace' && $job->status === self::STATUS_REVIEW) {
            // Analysed and reviewed: apply the already-prepared targets. Do NOT
            // clear results here - that would delete the prepared item rows the
            // apply phase consumes.
            $DB->set_field('tool_imageextractor_job', 'status', self::STATUS_QUEUED, ['id' => $jobid]);
            $DB->set_field('tool_imageextractor_job', 'error', null, ['id' => $jobid]);
            $DB->set_field('tool_imageextractor_job', 'timecompleted', 0, ['id' => $jobid]);
            $task = new task\process_replace();
            $task->set_custom_data(['jobid' => $jobid, 'op' => 'apply']);
            \core\task\manager::queue_adhoc_task($task, true);
            return;
        }

        // Reset run state so a re-run starts cleanly. The counters are zeroed
        // here (cheap), but any previous run's item rows and backup files -
        // which can number in the millions - are NOT deleted synchronously:
        // that could exceed the gateway timeout. The processing task clears
        // them on the next cron (clearfirst), so queueing returns immediately.
        self::zero_counters($jobid);
        $DB->set_field('tool_imageextractor_job', 'status', self::STATUS_QUEUED, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'error', null, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timestarted', 0, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timecompleted', 0, ['id' => $jobid]);

        if ($job->jobtype === 'replace') {
            // No prior analyse phase (e.g. driven from the CLI): the apply task
            // clears any stale results and prepares the targets itself before
            // its first batch.
            $task = new task\process_replace();
            $task->set_custom_data(['jobid' => $jobid, 'op' => 'apply', 'clearfirst' => true]);
            \core\task\manager::queue_adhoc_task($task, true);
            return;
        }

        $task = new task\process_job();
        $task->set_custom_data(['jobid' => $jobid, 'clearfirst' => true]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue the background analyse phase of a replace job: match the targets
     * and resolve their replacements without changing anything, then leave the
     * job awaiting review. Nothing is replaced until the admin confirms the
     * resulting preview, which queues the apply phase via queue_job().
     *
     * This keeps the (potentially minutes-long) scan of the file table out of
     * the web request, which used to time out at the gateway on large sites.
     *
     * @param int $jobid
     * @return void
     */
    public static function queue_analyse(int $jobid): void {
        global $DB;

        $job = self::get_job($jobid);
        if ($job->jobtype !== 'replace') {
            throw new \coding_exception('Only replace jobs have an analyse phase.');
        }
        // Analysing clears previous results, so the same stranded-backup guard
        // as queue_job() applies.
        if (self::has_restorable($jobid)) {
            throw new \moodle_exception('cannotrerunwithbackups', 'tool_imageextractor');
        }

        // Zero the counters now (cheap) but defer deleting any previous
        // analysis' item rows and backups to the task: on a large site that
        // delete can run for minutes and must not block the web request. The
        // analyse task clears them before it re-matches.
        self::zero_counters($jobid);
        $DB->set_field('tool_imageextractor_job', 'status', self::STATUS_QUEUED, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'error', null, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timestarted', 0, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timecompleted', 0, ['id' => $jobid]);

        $task = new task\process_replace();
        $task->set_custom_data(['jobid' => $jobid, 'op' => 'analyse']);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Reset a job's results, returning it to draft. If the job holds prepared
     * item rows - potentially millions, whose deletion (with their backup
     * files) can exceed the gateway timeout - the removal is deferred to a
     * background task that runs on the next cron; the job is parked in the
     * "clearing" state meanwhile. A job with nothing heavy to remove is reset
     * inline.
     *
     * @param int $jobid
     * @return bool True if the reset was deferred to a background task.
     */
    public static function reset_results(int $jobid): bool {
        global $DB;

        if ($DB->record_exists('tool_imageextractor_item', ['jobid' => $jobid])) {
            // Remove the bounded outputs (volumes, manifest, counters) now so
            // the job page stops advertising and serving the previous run's
            // downloads the instant it is cleared or edited; defer only the
            // heavy item-row and per-item backup delete to the task.
            self::clear_outputs($jobid);
            $DB->set_field('tool_imageextractor_job', 'status', self::STATUS_CLEARING, ['id' => $jobid]);
            $DB->set_field('tool_imageextractor_job', 'error', null, ['id' => $jobid]);
            $task = new task\reset_job();
            $task->set_custom_data(['jobid' => $jobid]);
            \core\task\manager::queue_adhoc_task($task, true);
            return true;
        }

        self::clear_results($jobid);
        self::mark_draft($jobid);
        return false;
    }

    /**
     * Return a job to a clean draft state (status and run timestamps only; this
     * does not touch results - pair it with clear_results()).
     *
     * @param int $jobid
     * @return void
     */
    public static function mark_draft(int $jobid): void {
        global $DB;
        $DB->set_field('tool_imageextractor_job', 'status', self::STATUS_DRAFT, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'error', null, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timestarted', 0, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timecompleted', 0, ['id' => $jobid]);
    }

    /**
     * Exact summary of an analysed replace job for the review screen, read
     * from the prepared item rows - cheap indexed queries, no file-table scan.
     *
     * @param int $jobid
     * @param int $samplelimit Maximum sample rows to return.
     * @return array ['total','willreplace','willskip','truncated','rows']
     */
    public static function review_summary(int $jobid, int $samplelimit = 50): array {
        global $DB;

        $job = self::get_job($jobid);
        $willreplace = $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $jobid, 'status' => 'pending']
        );
        $willskip = $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $jobid, 'status' => 'skipped']
        );
        $rows = array_values($DB->get_records(
            'tool_imageextractor_item',
            ['jobid' => $jobid],
            'id ASC',
            'id, contextid, component, filearea, fileitemid, filepath, filename, mimetype, filesize, replacementname',
            0,
            $samplelimit
        ));

        return [
            'total'       => (int) $job->totalmatched,
            'willreplace' => $willreplace,
            'willskip'    => $willskip,
            'truncated'   => ((int) $job->totalmatched) > count($rows),
            'rows'        => $rows,
        ];
    }

    /**
     * Queue a restore (undo) of a completed replace job, reverting every
     * replaced file from its backup.
     *
     * @param int $jobid
     * @return void
     */
    public static function queue_restore(int $jobid): void {
        global $DB;

        $DB->set_field('tool_imageextractor_job', 'status', self::STATUS_QUEUED, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'error', null, ['id' => $jobid]);
        $DB->set_field('tool_imageextractor_job', 'timecompleted', 0, ['id' => $jobid]);

        $task = new task\process_replace();
        $task->set_custom_data(['jobid' => $jobid, 'op' => 'restore']);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Whether a completed replace job still has restorable backups.
     *
     * @param int $jobid
     * @return bool
     */
    public static function has_restorable(int $jobid): bool {
        global $DB;
        // Only meaningful when the job actually kept backups; a replaced item
        // with no backup file cannot be restored.
        if (!$DB->record_exists('tool_imageextractor_job', ['id' => $jobid, 'backup' => 1])) {
            return false;
        }
        return $DB->record_exists(
            'tool_imageextractor_item',
            ['jobid' => $jobid, 'status' => 'done']
        );
    }

    /**
     * Remove all generated results for a job (items, volumes, files) but keep
     * the job definition itself.
     *
     * @param int $jobid
     * @return void
     */
    public static function clear_results(int $jobid): void {
        global $DB;
        $fs = get_file_storage();
        $context = self::context();

        // Replace jobs keep a per-item backup of the original; remove those in
        // one set-based pass. A job can hold millions of item rows, so looping
        // over ids (one query each) stalls the web request - this call's cost
        // scales with the backup files that actually exist instead.
        $fs->delete_area_files_select(
            $context->id,
            self::COMPONENT,
            'backup',
            'IN (SELECT id FROM {tool_imageextractor_item} WHERE jobid = :jobid)',
            ['jobid' => $jobid]
        );

        $DB->delete_records('tool_imageextractor_item', ['jobid' => $jobid]);

        // Remove the bounded, user-visible outputs too.
        self::clear_outputs($jobid);
    }

    /**
     * Remove a job's generated outputs - the ZIP volumes, their rows, the
     * manifest - and zero its counters, without touching the (potentially huge)
     * item rows or per-item backups. These artifacts are few and bounded (one
     * manifest and a handful of volume files), so this is always cheap enough to
     * run in a web request; it is what the job page advertises for download, so
     * clearing it synchronously stops stale downloads being served the moment a
     * job is cleared or edited, even while the heavy item/backup delete is still
     * deferred to cron.
     *
     * @param int $jobid
     * @return void
     */
    public static function clear_outputs(int $jobid): void {
        global $DB;
        $fs = get_file_storage();
        $context = self::context();

        // Volumes are stored under the job id as their file-area item id.
        $fs->delete_area_files($context->id, self::COMPONENT, 'volumes', $jobid);
        $DB->delete_records('tool_imageextractor_volume', ['jobid' => $jobid]);
        $fs->delete_area_files($context->id, self::COMPONENT, 'manifest', $jobid);

        self::zero_counters($jobid);
    }

    /**
     * Reset a job's progress and totals counters to zero, without touching its
     * results or status.
     *
     * @param int $jobid
     * @return void
     */
    public static function zero_counters(int $jobid): void {
        global $DB;
        foreach (['totalmatched', 'totalbytes', 'processedcount', 'processedbytes', 'failedcount', 'volumecount'] as $field) {
            $DB->set_field('tool_imageextractor_job', $field, 0, ['id' => $jobid]);
        }
    }

    /**
     * Delete a job entirely, including its definition and all files.
     *
     * @param int $jobid
     * @return void
     */
    public static function delete_job(int $jobid): void {
        global $DB;
        self::clear_results($jobid);
        $fs = get_file_storage();
        $fs->delete_area_files(self::context()->id, self::COMPONENT, 'csv', $jobid);
        $fs->delete_area_files(self::context()->id, self::COMPONENT, 'replacement', $jobid);
        $DB->delete_records('tool_imageextractor_job', ['id' => $jobid]);
    }

    /**
     * Return a job's generated ZIP volumes, in order.
     *
     * @param int $jobid
     * @return \stdClass[]
     */
    public static function get_volumes(int $jobid): array {
        global $DB;
        return $DB->get_records('tool_imageextractor_volume', ['jobid' => $jobid], 'sequence ASC');
    }

    /**
     * Whether a job currently has a downloadable manifest file.
     *
     * @param int $jobid
     * @return bool
     */
    public static function has_manifest(int $jobid): bool {
        $fs = get_file_storage();
        return (bool) $fs->get_file(
            self::context()->id,
            self::COMPONENT,
            'manifest',
            $jobid,
            '/',
            'manifest.csv'
        );
    }
}
