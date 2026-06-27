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
 * Scheduled task that removes old completed jobs and their archives.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;

/**
 * Deletes completed jobs (and the potentially large archives they produced)
 * once they are older than the configured retention period.
 */
class cleanup extends \core\task\scheduled_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'tool_imageextractor');
    }

    /**
     * Remove expired jobs.
     */
    public function execute() {
        global $DB;

        $days = (int) get_config('tool_imageextractor', 'retention_days');
        if ($days <= 0) {
            mtrace('tool_imageextractor: retention disabled, nothing to clean up');
            return;
        }

        $cutoff = time() - ($days * DAYSECS);
        $jobs = $DB->get_records_select(
            'tool_imageextractor_job',
            'status = :status AND timecompleted > 0 AND timecompleted < :cutoff',
            ['status' => manager::STATUS_COMPLETED, 'cutoff' => $cutoff]
        );

        foreach ($jobs as $job) {
            mtrace('tool_imageextractor: removing expired job ' . $job->id . ' ("' . $job->name . '")');
            manager::delete_job((int) $job->id);
        }
    }
}
