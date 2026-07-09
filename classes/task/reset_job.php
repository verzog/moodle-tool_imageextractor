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
 * Adhoc task that clears a job's results in the background.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;

/**
 * Removes a job's prepared items, backups and generated files, then returns it
 * to draft.
 *
 * A job can hold millions of item rows, whose deletion (with their backup
 * files) can run for minutes - far past the gateway timeout. Doing it here,
 * on the next cron with the time limit raised, keeps the "Clear results" and
 * edit-invalidation paths from timing out the web request.
 */
class reset_job extends \core\task\adhoc_task {
    /**
     * Cap how many of these run in parallel. Clearing writes to the file
     * storage, so the default of 1 avoids contention.
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
        if ($jobid <= 0) {
            return;
        }

        $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);
        if (!$job) {
            mtrace('tool_imageextractor: reset job ' . $jobid . ' no longer exists, skipping');
            return;
        }

        // Only clear a job that is actually parked for clearing; if it has moved
        // on (e.g. re-run since), leave it alone.
        if ($job->status !== manager::STATUS_CLEARING) {
            mtrace('tool_imageextractor: job ' . $jobid . ' is "' . $job->status . '", not clearing');
            return;
        }

        \core_php_time_limit::raise(0);
        \raise_memory_limit(MEMORY_EXTRA);

        mtrace('tool_imageextractor: clearing results for job ' . $jobid . ' ("' . $job->name . '")');
        manager::clear_results($jobid);
        manager::mark_draft($jobid);
        mtrace('tool_imageextractor: job ' . $jobid . ' reset to draft');
    }
}
