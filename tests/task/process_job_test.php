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
 * Tests for the extract processing task.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\task;

use tool_imageextractor\manager;

/**
 * Tests for process_job, focused on the batch-size throttle and packing the
 * already-matched items of an analysed job.
 *
 * @covers \tool_imageextractor\task\process_job
 * @covers \tool_imageextractor\manager::set_extract_action
 * @covers \tool_imageextractor\manager::queue_extract
 */
final class process_job_test extends \advanced_testcase {
    /**
     * Create $n image files in a fresh course's label intro area.
     *
     * @param int $n
     * @return void
     */
    protected function make_images(int $n): void {
        $course = $this->getDataGenerator()->create_course();
        $contextid = \context_course::instance($course->id)->id;
        $fs = get_file_storage();
        for ($i = 0; $i < $n; $i++) {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_label',
                'filearea'  => 'intro',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => "img{$i}.png",
            ], "png-content-{$i}");
        }
    }

    /**
     * Create a draft extract job matching the label intro images, with a volume
     * cap large enough that only the batch size bounds a run.
     *
     * @return \stdClass The job record.
     */
    protected function make_extract_job(): \stdClass {
        global $DB, $USER;

        $now = time();
        $job = (object) [
            'name'         => 'Batch cap',
            'jobtype'      => 'extract',
            'status'       => manager::STATUS_DRAFT,
            'criteria'     => json_encode([
                'imageonly' => true,
                'component' => 'mod_label',
                'filearea'  => 'intro',
            ]),
            'csvmode'      => 'none',
            'namingrule'   => '{originalname}',
            'replacemode'  => 'single',
            'backup'       => 0,
            'missingonly'  => 0,
            'dedupe'       => 0,
            'volumesize'   => 1073741824,
            'usermodified' => $USER->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record('tool_imageextractor_job', $job);
        return $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);
    }

    /**
     * Create a criteria-only draft job (no action chosen yet) matching the
     * label intro images.
     *
     * @return \stdClass The job record.
     */
    protected function make_draft_job(): \stdClass {
        global $DB, $USER;

        $now = time();
        $job = (object) [
            'name'         => 'Criteria only',
            'jobtype'      => '',
            'status'       => manager::STATUS_DRAFT,
            'criteria'     => json_encode([
                'imageonly' => true,
                'component' => 'mod_label',
                'filearea'  => 'intro',
            ]),
            'csvmode'      => 'none',
            'missingonly'  => 0,
            'usermodified' => $USER->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record('tool_imageextractor_job', $job);
        return $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);
    }

    /**
     * Run every queued task of a class, following requeues, until none remain.
     *
     * @param string $class The adhoc task class.
     * @return void
     */
    protected function drain(string $class): void {
        for ($i = 0; $i < 20; $i++) {
            if (!\core\task\manager::get_adhoc_tasks($class)) {
                return;
            }
            $this->runAdhocTasks($class);
        }
    }

    /**
     * The new flow: a criteria-only job is analysed into type-agnostic pending
     * items, then choosing Extract packs those already-matched items into a
     * volume, computing the output name at pack time (the task never re-matches).
     */
    public function test_analyse_then_extract_packs_matched_items(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/tool_imageextractor:/');
        set_config('enabled', 1, 'tool_imageextractor');
        set_config('throttle_delay', 0, 'tool_imageextractor');

        $this->make_images(3);
        $job = $this->make_draft_job();

        // Analyse records the matches as type-agnostic pending items.
        manager::queue_analyse($job->id);
        $this->drain(process_replace::class);

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_REVIEW, $job->status);
        $this->assertSame(3, (int) $job->totalmatched);
        $this->assertSame(3, $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $job->id, 'status' => 'pending']
        ));
        // The match left the output name blank; it is computed at pack time.
        $items = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id]);
        foreach ($items as $item) {
            $this->assertSame('', $item->outputname);
            $this->assertNull($item->replacementname);
        }

        // Choosing Extract records the options and packs the matched items.
        manager::set_extract_action($job->id, '{originalname}', 2048);
        manager::queue_extract($job->id);
        $this->drain(process_job::class);

        $job = manager::get_job($job->id);
        $this->assertSame(manager::STATUS_COMPLETED, $job->status);
        $this->assertSame('extract', $job->jobtype);
        $this->assertSame(3, (int) $job->processedcount);

        $volumes = manager::get_volumes($job->id);
        $this->assertNotEmpty($volumes);
        $packed = 0;
        foreach ($volumes as $volume) {
            $packed += (int) $volume->filecount;
        }
        $this->assertSame(3, $packed);

        // Every packed item now carries the output name it was packed under.
        $done = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id, 'status' => 'done']);
        $this->assertCount(3, $done);
        foreach ($done as $item) {
            $this->assertNotSame('', $item->outputname);
        }
    }

    /**
     * The analysed match records courseid=0 and a blank course shortname
     * (type-agnostic). Packing must resolve the real course and persist it, so
     * the item rows - and therefore the manifest and JSON sidecars - carry the
     * true course details rather than 0/blank.
     */
    public function test_extract_records_course_details(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/tool_imageextractor:/');
        set_config('enabled', 1, 'tool_imageextractor');
        set_config('throttle_delay', 0, 'tool_imageextractor');

        $course = $this->getDataGenerator()->create_course(['shortname' => 'BIO101']);
        $contextid = \context_course::instance($course->id)->id;
        $fs = get_file_storage();
        for ($i = 0; $i < 2; $i++) {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_label',
                'filearea'  => 'intro',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => "pic{$i}.png",
            ], "png-body-{$i}");
        }

        $job = $this->make_draft_job();
        manager::queue_analyse($job->id);
        $this->drain(process_replace::class);

        // The type-agnostic analyse leaves the course unset.
        $pending = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id]);
        foreach ($pending as $item) {
            $this->assertSame(0, (int) $item->courseid);
            $this->assertSame('', (string) $item->courseshortname);
        }

        manager::set_extract_action($job->id, '{originalname}', 2048);
        manager::queue_extract($job->id);
        $this->drain(process_job::class);

        // Packing resolves and persists the real course onto each item.
        $done = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id, 'status' => 'done']);
        $this->assertCount(2, $done);
        foreach ($done as $item) {
            $this->assertSame((int) $course->id, (int) $item->courseid);
            $this->assertSame('BIO101', (string) $item->courseshortname);
        }

        // The manifest, generated from the item rows, therefore shows it too.
        $manifest = $fs->get_file(
            \context_system::instance()->id,
            manager::COMPONENT,
            'manifest',
            $job->id,
            '/',
            'manifest.csv'
        );
        $this->assertNotFalse($manifest);
        $this->assertStringContainsString('BIO101', $manifest->get_content());
    }

    /**
     * A criteria-only job driven straight to extraction (the CLI/direct path
     * via queue_job) must pack every matched item, not collapse duplicate
     * content hashes - matching the web analyse->extract path. queue_job turns
     * de-duplication off even if the stored default left it on.
     */
    public function test_direct_extract_does_not_dedupe(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/tool_imageextractor:/');
        set_config('enabled', 1, 'tool_imageextractor');
        set_config('throttle_delay', 0, 'tool_imageextractor');

        // Two files with identical content share a content hash; with dedupe on
        // the old prepare would collapse them to one.
        $course = $this->getDataGenerator()->create_course();
        $contextid = \context_course::instance($course->id)->id;
        $fs = get_file_storage();
        foreach (['a.png', 'b.png'] as $name) {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_label',
                'filearea'  => 'intro',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $name,
            ], 'identical-content');
        }

        $job = $this->make_draft_job();
        // Simulate the stored dedupe default the direct path must override.
        $DB->set_field('tool_imageextractor_job', 'dedupe', 1, ['id' => $job->id]);

        manager::queue_job($job->id);
        $this->drain(process_job::class);

        $job = manager::get_job($job->id);
        $this->assertSame('extract', $job->jobtype);
        $this->assertSame(0, (int) $job->dedupe);
        // Both duplicate-content files were packed; neither was deduped away.
        $this->assertSame(2, (int) $job->totalmatched);
        $this->assertSame(2, (int) $job->processedcount);
    }

    /**
     * A single extract run packs at most one batch of files into a volume, even
     * when the volume-size cap is far from reached, leaving the rest pending for
     * the next (paced) run.
     */
    public function test_extract_run_capped_by_batch_size(): void {
        global $DB;
        $this->resetAfterTest();
        // The task narrates its progress via mtrace; own that output.
        $this->expectOutputRegex('/tool_imageextractor:/');
        set_config('enabled', 1, 'tool_imageextractor');
        set_config('batch_size', 2, 'tool_imageextractor');

        $this->make_images(3);
        $job = $this->make_extract_job();

        manager::queue_job($job->id);

        // Run exactly one task execution (not the whole drain), so we observe a
        // single run's output rather than the job draining to completion.
        $tasks = \core\task\manager::get_adhoc_tasks(process_job::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $task->execute();

        // All three matched, but this one run packed only a batch of two.
        $job = manager::get_job($job->id);
        $this->assertSame(3, (int) $job->totalmatched);
        $this->assertSame(2, (int) $job->processedcount);

        $volumes = manager::get_volumes($job->id);
        $this->assertCount(1, $volumes);
        $this->assertSame(2, (int) reset($volumes)->filecount);

        // The third file waits for the next run.
        $this->assertSame(1, $DB->count_records(
            'tool_imageextractor_item',
            ['jobid' => $job->id, 'status' => 'pending']
        ));
    }
}
