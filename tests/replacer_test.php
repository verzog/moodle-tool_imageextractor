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
 * Tests for the replace/restore engine.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the replace/restore engine.
 *
 * @covers \tool_imageextractor\replacer
 */
final class replacer_test extends \advanced_testcase {
    /**
     * Create a replace job record and its single replacement source.
     *
     * @param array $criteria
     * @param string $replacementcontent
     * @return \stdClass The job record.
     */
    protected function make_replace_job(array $criteria, string $replacementcontent): \stdClass {
        global $DB, $USER;

        $now = time();
        $job = (object) [
            'name'         => 'Test replace',
            'jobtype'      => 'replace',
            'status'       => manager::STATUS_QUEUED,
            'criteria'     => json_encode($criteria),
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

        // Store the single replacement image in the job's replacement area.
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
     * A replace swaps the target's content and keeps a backup; restore puts it
     * back.
     */
    public function test_replace_and_restore(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $fs = get_file_storage();
        $location = [
            'contextid' => $coursecontext->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        $fs->create_file_from_string($location, 'OLD');

        $job = $this->make_replace_job(
            [
                'imageonly'       => true,
                'component'       => 'mod_label',
                'filearea'        => 'intro',
                'filenamepattern' => 'logo.png',
            ],
            'NEW'
        );

        $replacer = new replacer($job);
        $replacer->prepare();
        $remaining = $replacer->apply_batch(50);

        $this->assertSame(0, $remaining);

        // The file at the location now holds the replacement content.
        $current = $fs->get_file(
            $location['contextid'],
            $location['component'],
            $location['filearea'],
            $location['itemid'],
            $location['filepath'],
            $location['filename']
        );
        $this->assertNotFalse($current);
        $this->assertSame('NEW', $current->get_content());

        // A backup of the original exists for the item.
        $item = $DB->get_record('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertSame('done', $item->status);
        $backup = $fs->get_file(
            \context_system::instance()->id,
            manager::COMPONENT,
            'backup',
            $item->id,
            '/',
            'logo.png'
        );
        $this->assertNotFalse($backup);
        $this->assertSame('OLD', $backup->get_content());

        // Restore puts the original back.
        $remaining = $replacer->restore_batch(50);
        $this->assertSame(0, $remaining);
        $restored = $fs->get_file(
            $location['contextid'],
            $location['component'],
            $location['filearea'],
            $location['itemid'],
            $location['filepath'],
            $location['filename']
        );
        $this->assertSame('OLD', $restored->get_content());
        $item = $DB->get_record('tool_imageextractor_item', ['id' => $item->id]);
        $this->assertSame('restored', $item->status);
    }

    /**
     * The public replacement resolver returns the exact stored file that apply
     * would use - including its real filepath when a ZIP replacement stored the
     * entry inside a folder - so the review preview links the right image.
     */
    public function test_replacement_for_resolves_stored_file(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \context_system::instance();
        $fs = get_file_storage();

        // Single mode: the one replacement applies to every target, at root.
        $single = $this->make_replace_job(['imageonly' => true], 'NEW');
        $resolved = (new replacer($single))->replacement_for('whatever.png');
        $this->assertNotNull($resolved);
        $this->assertSame('/', $resolved->get_filepath());
        $this->assertSame('new.png', $resolved->get_filename());

        // Zip mode: entries can live inside folders, matched by basename. The
        // resolver must return the file at its real (foldered) path.
        $zipjob = $this->make_replace_job(['imageonly' => true], 'IGNORED');
        $DB->set_field('tool_imageextractor_job', 'replacemode', 'zip', ['id' => $zipjob->id]);
        $fs->delete_area_files($context->id, manager::COMPONENT, 'replacement', $zipjob->id);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'replacement',
            'itemid'    => $zipjob->id,
            'filepath'  => '/branding/',
            'filename'  => 'logo.png',
        ], 'ZIPPED');
        $zipjob = $DB->get_record('tool_imageextractor_job', ['id' => $zipjob->id]);

        $replacer = new replacer($zipjob);
        $match = $replacer->replacement_for('logo.png');
        $this->assertNotNull($match);
        $this->assertSame('/branding/', $match->get_filepath());
        $this->assertSame('logo.png', $match->get_filename());
        // A target with no matching entry resolves to nothing (placeholder).
        $this->assertNull($replacer->replacement_for('absent.png'));
    }

    /**
     * Missing-only mode targets only files whose content is gone.
     */
    public function test_missing_only(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \context_system::instance();
        $fs = get_file_storage();
        $good = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'good.png',
        ], 'GOOD');

        // The "good" file is readable, so missing-only must skip it.
        $this->assertFalse(replacer::content_missing($good));

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'NEW'
        );
        $DB->set_field('tool_imageextractor_job', 'missingonly', 1, ['id' => $job->id]);
        $job = $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);

        $replacer = new replacer($job);
        $replacer->prepare();

        // No broken files exist, so nothing should have been queued.
        $this->assertSame(0, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
    }
}
