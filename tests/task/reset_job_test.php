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
 * Tests for deferred (background) clearing of a job's results.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;

/**
 * Tests for reset_job and manager::reset_results().
 *
 * @covers \tool_imageextractor\task\reset_job
 * @covers \tool_imageextractor\manager::reset_results
 */
final class reset_job_test extends \advanced_testcase {
    /**
     * Insert a completed extract job.
     *
     * @param int $totalmatched
     * @return \stdClass The job record.
     */
    protected function make_completed_job(int $totalmatched): \stdClass {
        global $DB, $USER;

        $now = time();
        $job = (object) [
            'name'         => 'Reset test',
            'jobtype'      => 'extract',
            'status'       => manager::STATUS_COMPLETED,
            'criteria'     => json_encode(['imageonly' => true]),
            'csvmode'      => 'none',
            'namingrule'   => '{originalname}',
            'replacemode'  => 'single',
            'backup'       => 0,
            'missingonly'  => 0,
            'dedupe'       => 0,
            'volumesize'   => 1048576,
            'totalmatched' => $totalmatched,
            'usermodified' => $USER->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record('tool_imageextractor_job', $job);
        return $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);
    }

    /**
     * Insert one done item row for a job.
     *
     * @param int $jobid
     * @return void
     */
    protected function make_item(int $jobid): void {
        global $DB;
        $DB->insert_record('tool_imageextractor_item', (object) [
            'jobid'           => $jobid,
            'fileid'          => 0,
            'contenthash'     => str_repeat('a', 40),
            'filename'        => 'logo.png',
            'filesize'        => 10,
            'mimetype'        => 'image/png',
            'contextid'       => \context_system::instance()->id,
            'component'       => 'mod_label',
            'filearea'        => 'intro',
            'filepath'        => '/',
            'fileitemid'      => 0,
            'uploaderid'      => 0,
            'filetimecreated' => 0,
            'courseid'        => 0,
            'courseshortname' => '',
            'outputname'      => 'logo.png',
            'volume'          => 0,
            'status'          => 'done',
            'timeprocessed'   => time(),
        ]);
    }

    /**
     * Record a generated ZIP volume (row plus file) and a manifest for a job,
     * as a completed extract run would leave behind.
     *
     * @param int $jobid
     * @return void
     */
    protected function make_outputs(int $jobid): void {
        global $DB;
        $DB->insert_record('tool_imageextractor_volume', (object) [
            'jobid'       => $jobid,
            'sequence'    => 1,
            'filename'    => 'images-volume-001.zip',
            'filesize'    => 3,
            'filecount'   => 1,
            'timecreated' => time(),
        ]);
        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'volumes',
            'itemid'    => $jobid,
            'filepath'  => '/',
            'filename'  => 'images-volume-001.zip',
        ], 'zip');
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'manifest',
            'itemid'    => $jobid,
            'filepath'  => '/',
            'filename'  => 'manifest.csv',
        ], 'name');
    }

    /**
     * A job with prepared items defers its clear to a background task and parks
     * in the "clearing" state, so the web request never runs the big delete -
     * but its downloadable outputs (volumes, manifest, counters) are removed
     * synchronously so the job page stops serving the old run at once.
     */
    public function test_reset_results_defers_when_items_exist(): void {
        global $DB;
        $this->resetAfterTest();
        // The task narrates its progress via mtrace; own that output.
        $this->expectOutputRegex('/tool_imageextractor:/');

        $job = $this->make_completed_job(1);
        $this->make_item($job->id);
        $this->make_outputs($job->id);

        $deferred = manager::reset_results($job->id);

        $this->assertTrue($deferred);
        // Parked for clearing, with the item still present until the task runs.
        $this->assertSame(manager::STATUS_CLEARING, manager::get_job($job->id)->status);
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(reset_job::class));
        // The downloadable outputs are gone immediately - no stale downloads
        // while the heavy delete waits for cron.
        $this->assertCount(0, manager::get_volumes($job->id));
        $this->assertFalse(manager::has_manifest($job->id));
        $this->assertSame(0, (int) manager::get_job($job->id)->totalmatched);

        $this->runAdhocTasks(reset_job::class);

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_DRAFT, $job->status);
        $this->assertSame(0, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
        $this->assertSame(0, (int) $job->totalmatched);
    }

    /**
     * A job with nothing heavy to remove is reset inline: no task is queued and
     * it returns to draft immediately.
     */
    public function test_reset_results_inline_when_empty(): void {
        $this->resetAfterTest();

        $job = $this->make_completed_job(0);

        $deferred = manager::reset_results($job->id);

        $this->assertFalse($deferred);
        $this->assertSame(manager::STATUS_DRAFT, manager::get_job($job->id)->status);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(reset_job::class));
    }

    /**
     * The background task refuses to clear a job that has moved on from the
     * "clearing" state (e.g. it was re-run before the task ran).
     */
    public function test_reset_job_skips_when_not_clearing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/is "completed", not clearing/');

        $job = $this->make_completed_job(1);
        $this->make_item($job->id);

        $task = new reset_job();
        $task->set_custom_data(['jobid' => $job->id]);
        $task->execute();

        // Still completed, item untouched.
        $this->assertSame(manager::STATUS_COMPLETED, manager::get_job($job->id)->status);
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
    }
}
