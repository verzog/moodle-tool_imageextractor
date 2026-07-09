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
 * Tests for the two-phase replace task flow (analyse, review, apply).
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;

/**
 * Tests for the two-phase replace task flow (analyse, review, apply).
 *
 * @covers \tool_imageextractor\task\process_replace
 * @covers \tool_imageextractor\manager
 */
final class process_replace_test extends \advanced_testcase {
    /**
     * Enable the plugin and the replace feature.
     *
     * @return void
     */
    protected function enable_plugin(): void {
        set_config('enabled', 1, 'tool_imageextractor');
        set_config('allow_replace', 1, 'tool_imageextractor');
    }

    /**
     * Create a target image file inside a course.
     *
     * Returns the file's location, not the stored_file: replacing deletes and
     * recreates the file, so its id changes while the location is stable.
     *
     * @param string $content
     * @return array File location, as get_file() arguments.
     */
    protected function make_target(string $content): array {
        $course = $this->getDataGenerator()->create_course();
        $location = [
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        get_file_storage()->create_file_from_string($location, $content);
        return $location;
    }

    /**
     * Read the content currently stored at a target location.
     *
     * @param array $location
     * @return string
     */
    protected function content_at(array $location): string {
        $file = get_file_storage()->get_file(
            $location['contextid'],
            $location['component'],
            $location['filearea'],
            $location['itemid'],
            $location['filepath'],
            $location['filename']
        );
        $this->assertNotFalse($file);
        return $file->get_content();
    }

    /**
     * Create a single-mode replace job targeting mod_label/intro images.
     *
     * @param string $replacementcontent
     * @return \stdClass The job record.
     */
    protected function make_replace_job(string $replacementcontent): \stdClass {
        global $DB, $USER;

        $now = time();
        $job = (object) [
            'name'         => 'Async replace',
            'jobtype'      => 'replace',
            'status'       => manager::STATUS_DRAFT,
            'criteria'     => json_encode([
                'imageonly' => true,
                'component' => 'mod_label',
                'filearea'  => 'intro',
            ]),
            'csvmode'      => 'none',
            'namingrule'   => '{originalname}',
            'replacemode'  => 'single',
            'backup'       => 1,
            'missingonly'  => 0,
            'dedupe'       => 0,
            'volumesize'   => 1048576,
            'usermodified' => $USER->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record('tool_imageextractor_job', $job);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'replacement',
            'itemid'    => $job->id,
            'filepath'  => '/',
            'filename'  => 'new.png',
        ], $replacementcontent);

        return $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);
    }

    /**
     * Run every queued replace task, following requeues, until none remain.
     *
     * @return void
     */
    protected function drain_tasks(): void {
        // Each apply batch may requeue a follow-up task, so loop until drained.
        for ($i = 0; $i < 20; $i++) {
            $tasks = \core\task\manager::get_adhoc_tasks(process_replace::class);
            if (!$tasks) {
                return;
            }
            $this->runAdhocTasks(process_replace::class);
        }
    }

    /**
     * The web flow: analyse in the background, park for review with nothing
     * changed, then apply only after the review is confirmed.
     */
    public function test_analyse_review_apply_flow(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_plugin();

        $target = $this->make_target('OLD');
        $job = $this->make_replace_job('NEW');

        // Phase 1: analyse.
        manager::queue_analyse($job->id);
        $this->assertSame(manager::STATUS_QUEUED, manager::get_job($job->id)->status);
        $this->drain_tasks();

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_REVIEW, $job->status);
        $this->assertSame(1, (int) $job->totalmatched);

        // The analysis materialised the target with its resolved replacement,
        // but nothing has been replaced yet.
        $items = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertSame('pending', $item->status);
        $this->assertSame('new.png', $item->replacementname);
        $this->assertSame('OLD', $this->content_at($target));

        // The review summary reads the stored breakdown.
        $review = manager::review_summary($job->id);
        $this->assertSame(1, $review['total']);
        $this->assertSame(1, $review['willreplace']);
        $this->assertSame(0, $review['willskip']);
        $this->assertCount(1, $review['rows']);

        // Phase 2: confirm and apply.
        manager::queue_job($job->id);
        $this->drain_tasks();

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_COMPLETED, $job->status);
        $this->assertSame('NEW', $this->content_at($target));
        // Applying must consume the analysed targets, not re-prepare them.
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
    }

    /**
     * The CLI path still works in one shot: queueing apply directly prepares
     * the targets inside the task before applying.
     */
    public function test_direct_apply_still_prepares(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_plugin();

        $target = $this->make_target('OLD');
        $job = $this->make_replace_job('NEW');

        manager::queue_job($job->id);
        $this->drain_tasks();

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_COMPLETED, $job->status);
        $this->assertSame(1, (int) $job->totalmatched);
        $this->assertSame('NEW', $this->content_at($target));
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
    }

    /**
     * Discarding an analysis (clear results) removes the prepared targets and
     * stored totals, returning the job to a clean draft.
     */
    public function test_discard_analysis(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_plugin();

        $this->make_target('OLD');
        $job = $this->make_replace_job('NEW');

        manager::queue_analyse($job->id);
        $this->drain_tasks();
        $this->assertSame(manager::STATUS_REVIEW, manager::get_job($job->id)->status);

        manager::clear_results($job->id);
        $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_DRAFT, ['id' => $job->id]);

        $job = manager::get_job($job->id);
        $this->assertSame(0, (int) $job->totalmatched);
        $this->assertSame(0, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
    }
}
