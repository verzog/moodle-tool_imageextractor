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
 * Adhoc task that applies (or restores) a replace job in throttled batches.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;
use tool_imageextractor\replacer;

/**
 * Runs a replace job a batch at a time, re-queuing itself until done.
 *
 * Keeping each run to a fixed batch keeps a large replace job from blocking a
 * cron worker, and makes the job resumable after an interruption: remaining
 * targets stay pending for the next tick.
 */
class process_replace extends \core\task\adhoc_task {
    /**
     * Cap how many replace tasks run in parallel. Replacing files writes to
     * the file storage, so the default of 1 avoids contention.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        $configured = (int) get_config('tool_imageextractor', 'replace_concurrency');
        return $configured > 0 ? $configured : 1;
    }

    /**
     * Entry point.
     */
    public function execute() {
        global $DB;

        $data = (object) $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        $op = (string) ($data->op ?? 'apply');
        // A fresh run defers its (potentially huge) clear of the previous run's
        // items and backups to here, off the web request. A reviewed apply does
        // not set this - it must consume the prepared targets, not wipe them.
        $clearfirst = !empty($data->clearfirst);
        if ($jobid <= 0) {
            return;
        }

        $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);
        if (!$job) {
            mtrace('tool_imageextractor: replace job ' . $jobid . ' no longer exists, skipping');
            return;
        }

        if (!manager::is_enabled() || !manager::is_replace_allowed()) {
            // Throw rather than return so Moodle reschedules this adhoc task and
            // the job resumes once replace is re-enabled, instead of the task
            // being consumed and the job left stuck as queued forever.
            throw new \moodle_exception('disabledretry', 'tool_imageextractor');
        }

        if (!in_array($job->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true)) {
            mtrace('tool_imageextractor: replace job ' . $jobid . ' is "' . $job->status . '", nothing to do');
            return;
        }

        \core_php_time_limit::raise(0);
        \raise_memory_limit(MEMORY_EXTRA);

        try {
            $replacer = new replacer($job);
            $batch = manager::batch_size();

            if ($op === 'restore') {
                $remaining = $replacer->restore_batch($batch);
                mtrace('tool_imageextractor: replace job ' . $jobid . ' restored a batch, ' .
                    $remaining . ' remaining');
                if ($remaining > 0) {
                    $this->requeue(['jobid' => $jobid, 'op' => 'restore']);
                } else {
                    $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_COMPLETED, ['id' => $jobid]);
                    $DB->set_field('tool_imageextractor_job', 'timecompleted', time(), ['id' => $jobid]);
                    mtrace('tool_imageextractor: replace job ' . $jobid . ' restore complete');
                }
                return;
            }

            // Analyse and direct apply share a paced clear -> match progression,
            // one bounded batch per run so a large job never pins the database.
            // Analyse then parks for review; apply then replaces. A reviewed
            // apply skips straight to applying its already-prepared targets.
            $phase = (string) ($data->phase ?? '');
            if ($phase === '') {
                $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_PROCESSING, ['id' => $jobid]);
                $DB->set_field('tool_imageextractor_job', 'timestarted', time(), ['id' => $jobid]);
                // A reviewed apply already has its targets prepared and must not
                // re-clear or re-match - it just replaces them. Every other run
                // starts from the clear phase.
                $hasitems = $DB->record_exists('tool_imageextractor_item', ['jobid' => $jobid]);
                $reviewedapply = ($op === 'apply' && !$clearfirst && $hasitems);
                $phase = $reviewedapply ? 'apply' : 'clear';
            }

            if ($phase === 'clear') {
                $remaining = manager::clear_chunk($jobid, $batch);
                if ($remaining > 0) {
                    mtrace('tool_imageextractor: replace job ' . $jobid .
                        ' clearing previous results, ' . $remaining . ' item rows left');
                    $this->requeue(['jobid' => $jobid, 'op' => $op, 'phase' => 'clear']);
                    return;
                }
                // Items gone; drop the bounded outputs, zero the counters, match.
                manager::clear_outputs($jobid);
                mtrace('tool_imageextractor: matching replace job ' . $jobid . ' ("' . $job->name . '")');
                $this->requeue(['jobid' => $jobid, 'op' => $op, 'phase' => 'match', 'curid' => 0]);
                return;
            }

            if ($phase === 'match') {
                $curid = (int) ($data->curid ?? 0);
                $page = $replacer->prepare_page($curid, $batch);
                if ($page['matched']) {
                    manager::bump_totals($jobid, $page['matched'], $page['bytes']);
                }
                if (!$page['exhausted']) {
                    $this->requeue(['jobid' => $jobid, 'op' => $op, 'phase' => 'match', 'curid' => $page['lastid']]);
                    return;
                }
                $matched = (int) $DB->get_field('tool_imageextractor_job', 'totalmatched', ['id' => $jobid]);
                if ($op === 'analyse') {
                    $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_REVIEW, ['id' => $jobid]);
                    mtrace('tool_imageextractor: replace job ' . $jobid . ' analysed, ' .
                        $matched . ' targets matched; awaiting review');
                    return;
                }
                mtrace('tool_imageextractor: replace job ' . $jobid . ' matched ' .
                    $matched . ' targets; applying');
                $this->requeue(['jobid' => $jobid, 'op' => 'apply', 'phase' => 'apply']);
                return;
            }

            // Phase 'apply': replace the prepared targets a batch at a time.
            $remaining = $replacer->apply_batch($batch);
            mtrace('tool_imageextractor: replace job ' . $jobid . ' processed a batch, ' .
                $remaining . ' remaining');
            if ($remaining > 0) {
                $this->requeue(['jobid' => $jobid, 'op' => 'apply', 'phase' => 'apply']);
            } else {
                $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_COMPLETED, ['id' => $jobid]);
                $DB->set_field('tool_imageextractor_job', 'timecompleted', time(), ['id' => $jobid]);
                mtrace('tool_imageextractor: replace job ' . $jobid . ' complete');
            }
        } catch (\Throwable $e) {
            $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_FAILED, ['id' => $jobid]);
            $DB->set_field('tool_imageextractor_job', 'error', $e->getMessage(), ['id' => $jobid]);
            mtrace('tool_imageextractor: replace job ' . $jobid . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Queue another run of this task for the same job, carrying its phase and
     * cursor. The next run is paced a little into the future so the database is
     * left idle between bursts - otherwise one cron run grinds through every
     * batch back-to-back and starves the rest of the site on a small server.
     *
     * @param array $customdata Custom data for the next run (jobid, op, phase, curid).
     * @return void
     */
    protected function requeue(array $customdata) {
        $task = new process_replace();
        $task->set_custom_data($customdata);
        $delay = manager::throttle_delay();
        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }
        \core\task\manager::queue_adhoc_task($task);
    }
}
