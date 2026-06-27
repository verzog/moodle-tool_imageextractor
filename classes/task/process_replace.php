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
    /** @var int How many targets to process per run. */
    const BATCH_SIZE = 200;

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

            if ($op === 'restore') {
                $remaining = $replacer->restore_batch(self::BATCH_SIZE);
                mtrace('tool_imageextractor: replace job ' . $jobid . ' restored a batch, ' .
                    $remaining . ' remaining');
                if ($remaining > 0) {
                    $this->requeue($jobid, 'restore');
                } else {
                    $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_COMPLETED, ['id' => $jobid]);
                    $DB->set_field('tool_imageextractor_job', 'timecompleted', time(), ['id' => $jobid]);
                    mtrace('tool_imageextractor: replace job ' . $jobid . ' restore complete');
                }
                return;
            }

            if ($job->status === manager::STATUS_QUEUED) {
                mtrace('tool_imageextractor: preparing replace job ' . $jobid . ' ("' . $job->name . '")');
                $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_PROCESSING, ['id' => $jobid]);
                $DB->set_field('tool_imageextractor_job', 'timestarted', time(), ['id' => $jobid]);
                $replacer->prepare();
            }

            $remaining = $replacer->apply_batch(self::BATCH_SIZE);
            mtrace('tool_imageextractor: replace job ' . $jobid . ' processed a batch, ' .
                $remaining . ' remaining');

            if ($remaining > 0) {
                $this->requeue($jobid, 'apply');
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
     * Queue another run of this task for the same job.
     *
     * @param int $jobid
     * @param string $op
     * @return void
     */
    protected function requeue(int $jobid, string $op) {
        $task = new process_replace();
        $task->set_custom_data(['jobid' => $jobid, 'op' => $op]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
