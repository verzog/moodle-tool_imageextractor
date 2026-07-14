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
        // The task narrates its progress via mtrace; own that output so
        // PHPUnit does not flag the test as risky.
        $this->expectOutputRegex('/tool_imageextractor:/');
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

        // The analysis materialised the target as a type-agnostic pending item:
        // no replacement is resolved at match time (that happens at apply), and
        // nothing has been replaced yet.
        $items = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertSame('pending', $item->status);
        $this->assertNull($item->replacementname);
        $this->assertSame('OLD', $this->content_at($target));

        // The review summary reports the stored total and a bounded sample of
        // rows, without counting or aggregating over the item table.
        $review = manager::review_summary($job->id);
        $this->assertSame(1, $review['total']);
        $this->assertFalse($review['truncated']);
        $this->assertCount(1, $review['rows']);
        $this->assertArrayNotHasKey('willreplace', $review);

        // The sample row carries the fields the review page needs to build the
        // current-image thumbnail (its original location and mime type).
        $prow = $review['rows'][0];
        $this->assertSame('mod_label', $prow->component);
        $this->assertSame('intro', $prow->filearea);
        $this->assertSame('image/png', $prow->mimetype);
        $this->assertNotEmpty($prow->contextid);
        $this->assertSame('/', $prow->filepath);

        // Phase 2: confirm and apply.
        manager::queue_job($job->id);
        $this->drain_tasks();

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_COMPLETED, $job->status);
        $this->assertSame('NEW', $this->content_at($target));
        // Applying must consume the analysed targets, not re-prepare them.
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
        // Apply resolved the replacement per item and recorded its name.
        $item = $DB->get_record('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertSame('done', $item->status);
        $this->assertSame('new.png', $item->replacementname);
    }

    /**
     * The CLI path still works in one shot: queueing apply directly prepares
     * the targets inside the task before applying.
     */
    public function test_direct_apply_still_prepares(): void {
        global $DB;
        $this->resetAfterTest();
        // The task narrates its progress via mtrace; own that output so
        // PHPUnit does not flag the test as risky.
        $this->expectOutputRegex('/tool_imageextractor:/');
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
        // The task narrates its progress via mtrace; own that output so
        // PHPUnit does not flag the test as risky.
        $this->expectOutputRegex('/tool_imageextractor:/');
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

    /**
     * A target with no matching replacement is skipped (not failed) at apply
     * time, and its content is left untouched.
     */
    public function test_apply_skips_target_without_replacement(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/tool_imageextractor:/');
        $this->enable_plugin();

        $target = $this->make_target('OLD');
        $job = $this->make_replace_job('IGNORED');
        // Switch to ZIP mode and store an entry that does NOT match the target
        // filename, so resolution at apply finds nothing.
        $DB->set_field('tool_imageextractor_job', 'replacemode', 'zip', ['id' => $job->id]);
        $context = \context_system::instance();
        get_file_storage()->delete_area_files($context->id, manager::COMPONENT, 'replacement', $job->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'replacement',
            'itemid'    => $job->id,
            'filepath'  => '/',
            'filename'  => 'unrelated.png',
        ], 'ZIP');

        manager::queue_analyse($job->id);
        $this->drain_tasks();
        $this->assertSame(manager::STATUS_REVIEW, manager::get_job($job->id)->status);

        manager::queue_job($job->id);
        $this->drain_tasks();

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_COMPLETED, $job->status);
        // The target had no matching replacement, so it was skipped, not failed,
        // and its content is unchanged.
        $item = $DB->get_record('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertSame('skipped', $item->status);
        $this->assertSame('OLD', $this->content_at($target));
    }

    /**
     * A match larger than one batch is analysed across several throttled runs
     * (keyset pages), and the whole set is recorded exactly once - the paged,
     * idempotent matching neither drops nor duplicates targets.
     */
    public function test_analyse_pages_a_large_match(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/tool_imageextractor:/');
        $this->enable_plugin();
        // Three matching targets with a batch of two forces matching to span
        // more than one page.
        set_config('batch_size', 2, 'tool_imageextractor');

        $this->make_target('A');
        $this->make_target('B');
        $this->make_target('C');
        $job = $this->make_replace_job('NEW');

        manager::queue_analyse($job->id);
        $this->drain_tasks();

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_REVIEW, $job->status);
        $this->assertSame(3, (int) $job->totalmatched);
        // Exactly three item rows - no duplicates from the paged matching.
        $this->assertSame(3, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
        $this->assertSame(3, $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $job->id, 'status' => 'pending']
        ));
    }

    /**
     * While an analysis is scanning, the job reports live stage progress (an
     * upfront estimate as the denominator, batches scanned as the numerator)
     * so the UI can render a progress bar; the report is retired once the
     * exact totals exist.
     */
    public function test_analyse_reports_live_progress(): void {
        $this->resetAfterTest();
        $this->expectOutputRegex('/tool_imageextractor:/');
        $this->enable_plugin();
        // Three matching targets with a batch of two forces matching to span
        // more than one page; no throttle so requeued runs are due immediately.
        set_config('batch_size', 2, 'tool_imageextractor');
        set_config('throttle_delay', 0, 'tool_imageextractor');

        $this->make_target('A');
        $this->make_target('B');
        $this->make_target('C');
        $job = $this->make_replace_job('NEW');

        manager::queue_analyse($job->id);
        // Generation 1: the clear phase (nothing to remove) queues the match.
        $this->runAdhocTasks(process_replace::class);
        // Generation 2: the first match page scans 2 of the ~3 estimated files.
        $this->runAdhocTasks(process_replace::class);

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_PROCESSING, $job->status);
        $this->assertSame('match', $job->progressstage);
        $this->assertSame(2, (int) $job->progressdone);
        $this->assertSame(3, (int) $job->progresstotal);

        // Finishing the scan retires the stage report - the exact recounted
        // totals take over on the review screen.
        $this->drain_tasks();
        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_REVIEW, $job->status);
        $this->assertSame(3, (int) $job->totalmatched);
        $this->assertNull($job->progressstage);
        $this->assertSame(0, (int) $job->progresstotal);
    }
}
