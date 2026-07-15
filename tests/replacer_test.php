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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/replace_job_maker.php');

/**
 * Tests for the replace/restore engine.
 *
 * @covers \tool_imageextractor\replacer
 */
final class replacer_test extends \advanced_testcase {
    use replace_job_maker;

    /**
     * Create a replace job record and its single replacement source.
     *
     * @param array $criteria
     * @param string $replacementcontent
     * @return \stdClass The job record.
     */
    protected function make_replace_job(array $criteria, string $replacementcontent): \stdClass {
        return $this->make_replace_job_record(['criteria' => json_encode($criteria)], $replacementcontent);
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
     * Re-recording the same matching page (as a crash-retry would) does not
     * duplicate item rows: the page first clears anything at or beyond its
     * cursor. This keeps the throttled, paged matching safe to retry.
     */
    public function test_prepare_page_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ], 'OLD');

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'NEW'
        );
        $replacer = new replacer($job);

        $first = $replacer->prepare_page(0, 50);
        $this->assertSame(1, $first['matched']);
        $this->assertTrue($first['exhausted']);
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));

        // Replaying the same page from the same cursor must not add a duplicate.
        $replacer->prepare_page(0, 50);
        $this->assertSame(1, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));

        // And the totals, set from the recorded rows, are not inflated by the
        // replay - they reflect the one real target, not two.
        manager::recount_totals($job->id);
        $this->assertSame(1, (int) manager::get_job($job->id)->totalmatched);
    }

    /**
     * Replaying an earlier match page (as a crash-retry would) removes and
     * re-records only that page's own file ids - it must not wipe the rows a
     * later page has already written.
     */
    public function test_prepare_page_replay_keeps_later_pages(): void {
        global $DB;
        $this->resetAfterTest();

        $contextid = \context_course::instance($this->getDataGenerator()->create_course()->id)->id;
        $fs = get_file_storage();
        for ($i = 1; $i <= 4; $i++) {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_label',
                'filearea'  => 'intro',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => "img{$i}.png",
            ], "content-{$i}");
        }

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'NEW'
        );
        $replacer = new replacer($job);

        $a = $replacer->prepare_page(0, 2);
        $this->assertSame(2, $a['matched']);
        $b = $replacer->prepare_page($a['lastid'], 2);
        $this->assertSame(2, $b['matched']);
        $this->assertSame(4, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));

        // Replay the first page: it must touch only its own two file ids, so all
        // four rows remain (a broad "fileid > cursor" delete would drop page B).
        $replacer->prepare_page(0, 2);
        $this->assertSame(4, $DB->count_records('tool_imageextractor_item', ['jobid' => $job->id]));
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

    /**
     * A metadata-only replace stamps the new author/licence on the matched
     * file without touching its content (the file is not even recreated),
     * and records the old values in the item note.
     */
    public function test_metadata_only_replace(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \context_system::instance();
        $fs = get_file_storage();
        $target = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ], 'ORIGINAL');
        $target->set_author('Old Author');
        $target->set_license('allrightsreserved');

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'IGNORED'
        );
        $DB->set_field('tool_imageextractor_job', 'replacemode', 'metadata', ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'metaauthor', 'New Author', ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'metalicense', 'public', ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'backup', 0, ['id' => $job->id]);
        $job = $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);

        $replacer = new replacer($job);
        $replacer->prepare();
        $remaining = $replacer->apply_batch(10);
        $this->assertSame(0, $remaining);

        $stored = $fs->get_file($context->id, 'mod_label', 'intro', 0, '/', 'logo.png');
        // Content untouched and the file was updated in place, not recreated.
        $this->assertSame('ORIGINAL', $stored->get_content());
        // Same file id: the metadata update happens in place, it does not
        // delete and recreate the file. Cast because a DB-loaded stored_file
        // reports its id as a string on some drivers (e.g. PostgreSQL).
        $this->assertSame((int) $target->get_id(), (int) $stored->get_id());
        $this->assertSame('New Author', $stored->get_author());
        $this->assertSame('public', $stored->get_license());

        $item = $DB->get_record('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertSame('done', $item->status);
        $this->assertStringContainsString('Old Author', $item->note);
        $this->assertStringContainsString('allrightsreserved', $item->note);
        // Nothing was backed up (nothing destructive happened to the content).
        $this->assertFalse((bool) $fs->get_area_files(
            $context->id,
            manager::COMPONENT,
            'backup',
            (int) $item->id,
            'id',
            false
        ));
    }

    /**
     * Restore puts back the backup's own metadata, not the metadata that sits
     * on the replaced live file (which matters for a job replaced before
     * content-preservation existed - its replacement carries different values).
     */
    public function test_restore_uses_backup_metadata(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \context_system::instance();
        $fs = get_file_storage();
        $location = [
            'contextid' => $context->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'logo.png',
        ];
        $original = $fs->create_file_from_string($location, 'ORIGINAL');
        $original->set_author('Original Author');
        $original->set_license('cc-4.0');

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'NEW'
        );
        $replacer = new replacer($job);
        $replacer->prepare();
        $this->assertSame(0, $replacer->apply_batch(10));

        // Simulate an old-code replacement whose live file lost the original
        // metadata (author/licence now belong to the uploaded replacement).
        $replaced = $fs->get_file($context->id, 'mod_label', 'intro', 0, '/', 'logo.png');
        $replaced->set_author('Replacement Author');
        $replaced->set_license('allrightsreserved');

        $this->assertSame(0, $replacer->restore_batch(10));

        $restored = $fs->get_file($context->id, 'mod_label', 'intro', 0, '/', 'logo.png');
        $this->assertSame('ORIGINAL', $restored->get_content());
        // The backup carried the original metadata, so restore brings it back
        // rather than leaving the replacement's behind.
        $this->assertSame('Original Author', $restored->get_author());
        $this->assertSame('cc-4.0', $restored->get_license());
    }

    /**
     * Create a Page whose content embeds the given image files, storing a
     * stub file for every @@PLUGINFILE@@ reference in the content.
     *
     * @param string $content The page's HTML (with @@PLUGINFILE@@/name refs).
     * @return array [\stdClass $page, \context_module $modcontext]
     */
    protected function make_page_with_images(string $content): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course'        => $course->id,
            'content'       => $content,
            'contentformat' => FORMAT_HTML,
        ]);
        $modcontext = \context_module::instance($page->cmid);
        preg_match_all('#@@PLUGINFILE@@/([^"\'\s>]+)#', $content, $matches);
        foreach (array_unique($matches[1]) as $filename) {
            get_file_storage()->create_file_from_string([
                'contextid' => $modcontext->id,
                'component' => 'mod_page',
                'filearea'  => 'content',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => rawurldecode($filename),
            ], 'PNGDATA');
        }
        return [$page, $modcontext];
    }

    /**
     * Create an alt-text replace job scoped to Page content, with the given
     * descriptions CSV body and backup flag.
     *
     * @param string $csvbody The alt.csv contents.
     * @param int $backup Whether to keep an HTML backup for Restore.
     * @return \stdClass The job record.
     */
    protected function make_alt_job(string $csvbody, int $backup): \stdClass {
        $job = $this->make_replace_job_record([
            'criteria'    => json_encode(['imageonly' => true, 'component' => 'mod_page', 'filearea' => 'content']),
            'replacemode' => 'alttext',
            'backup'      => $backup,
        ], 'unused');
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'altcsv',
            'itemid'    => $job->id,
            'filepath'  => '/',
            'filename'  => 'alt.csv',
        ], $csvbody);
        return $job;
    }

    /**
     * Alt-text mode rewrites the description in the HTML that embeds the image,
     * from the uploaded CSV, without touching the file content.
     */
    public function test_alttext_apply_updates_embedding_html(): void {
        global $DB;
        $this->resetAfterTest();

        [$page, $modcontext] = $this->make_page_with_images('<p><img src="@@PLUGINFILE@@/pic.png" alt="before"></p>');
        $job = $this->make_alt_job("filename,alttext\npic.png,\"A helpful diagram\"\n", 0);

        $replacer = new replacer($job);
        $replacer->prepare();
        $this->assertSame(0, $replacer->apply_batch(10));

        $item = $DB->get_record('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertSame('done', $item->status);
        $this->assertStringContainsString('before', $item->note);

        // The page content now carries the new description; the file bytes are
        // untouched.
        $content = $DB->get_field('page', 'content', ['id' => $page->id]);
        $this->assertStringContainsString('alt="A helpful diagram"', $content);
        $this->assertStringNotContainsString('alt="before"', $content);
        $stored = get_file_storage()->get_file($modcontext->id, 'mod_page', 'content', 0, '/', 'pic.png');
        $this->assertSame('PNGDATA', $stored->get_content());
    }

    /**
     * With backup on, an alt-text replace is reversible: Restore writes the
     * original HTML back, drops the field backups, and marks items restored.
     */
    public function test_alttext_restore_reverts_html(): void {
        global $DB;
        $this->resetAfterTest();

        [$page] = $this->make_page_with_images('<p><img src="@@PLUGINFILE@@/pic.png" alt="before"></p>');
        $job = $this->make_alt_job("filename,alttext\npic.png,\"after\"\n", 1);

        $replacer = new replacer($job);
        $replacer->prepare();
        $this->assertSame(0, $replacer->apply_batch(10));

        // Applied, backed up, and reported as restorable.
        $this->assertStringContainsString('alt="after"', $DB->get_field('page', 'content', ['id' => $page->id]));
        $this->assertSame(1, $DB->count_records('tool_imageextractor_htmlbackup', ['jobid' => $job->id]));
        $this->assertTrue(manager::has_restorable($job->id));

        // Restore puts the original description back and clears the backups.
        $this->assertSame(0, $replacer->restore_batch(10));
        $content = $DB->get_field('page', 'content', ['id' => $page->id]);
        $this->assertStringContainsString('alt="before"', $content);
        $this->assertStringNotContainsString('alt="after"', $content);
        $this->assertSame(0, $DB->count_records('tool_imageextractor_htmlbackup', ['jobid' => $job->id]));
        $this->assertSame('restored', $DB->get_field('tool_imageextractor_item', 'status', ['jobid' => $job->id]));
        $this->assertFalse(manager::has_restorable($job->id));
    }

    /**
     * The "missing alt" refinement keeps only images embedded in content
     * through an <img> with an empty/missing alt.
     */
    public function test_altmissing_refinement(): void {
        global $DB;
        $this->resetAfterTest();

        $this->make_page_with_images(
            '<p><img src="@@PLUGINFILE@@/described.png" alt="a car">'
            . '<img src="@@PLUGINFILE@@/bare.png"></p>'
        );
        $job = $this->make_replace_job_record([
            'criteria'   => json_encode(['imageonly' => true, 'component' => 'mod_page', 'filearea' => 'content']),
            'altmissing' => 1,
        ], 'unused');

        $replacer = new replacer($job);
        $replacer->prepare();

        // Only the undescribed image is matched.
        $items = $DB->get_records('tool_imageextractor_item', ['jobid' => $job->id]);
        $this->assertCount(1, $items);
        $this->assertSame('bare.png', reset($items)->filename);
    }

    /**
     * A content replace preserves the target's own metadata (author, licence)
     * across the swap instead of adopting the uploaded replacement's.
     */
    public function test_replace_preserves_target_metadata(): void {
        global $DB;
        $this->resetAfterTest();

        $context = \context_system::instance();
        $fs = get_file_storage();
        $target = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ], 'OLD');
        $target->set_author('Course Author');
        $target->set_license('cc-4.0');

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'NEW'
        );
        $replacer = new replacer($job);
        $replacer->prepare();
        $this->assertSame(0, $replacer->apply_batch(10));

        $stored = $fs->get_file($context->id, 'mod_label', 'intro', 0, '/', 'photo.png');
        $this->assertSame('NEW', $stored->get_content());
        $this->assertSame('Course Author', $stored->get_author());
        $this->assertSame('cc-4.0', $stored->get_license());
    }

    /**
     * Optimizing replacements caps the longest edge in place (same location,
     * same name, same mime type), so filename matching is unaffected.
     */
    public function test_optimize_replacements(): void {
        global $DB;
        $this->resetAfterTest();

        $job = $this->make_replace_job(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            'placeholder'
        );

        // Swap the placeholder replacement for a real 400x200 PNG.
        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, manager::COMPONENT, 'replacement', $job->id);
        $image = imagecreatetruecolor(400, 200);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'replacement',
            'itemid'    => $job->id,
            'filepath'  => '/',
            'filename'  => 'big.png',
        ], $png);

        $DB->set_field('tool_imageextractor_job', 'optimizemaxpx', 100, ['id' => $job->id]);
        $DB->set_field('tool_imageextractor_job', 'optimizequality', 80, ['id' => $job->id]);
        $job = $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);

        $replacer = new replacer($job);
        $this->assertSame(1, $replacer->count_replacements());
        $result = $replacer->optimize_page('', 10);
        $this->assertTrue($result['exhausted']);
        $this->assertSame(1, $result['processed']);

        $optimized = $fs->get_file($context->id, manager::COMPONENT, 'replacement', $job->id, '/', 'big.png');
        $this->assertNotFalse($optimized);
        $this->assertSame('image/png', $optimized->get_mimetype());
        $info = $optimized->get_imageinfo();
        $this->assertSame(100, (int) $info['width']);
        $this->assertSame(50, (int) $info['height']);
    }
}
