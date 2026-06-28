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
 * Tests for the file-storage matcher.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the file-storage matcher.
 *
 * @covers \tool_imageextractor\matcher
 */
final class matcher_test extends \advanced_testcase {
    /**
     * Helper to create a stored file in a context.
     *
     * @param int $contextid
     * @param string $filename
     * @param string $content
     * @param string $component
     * @param string $filearea
     * @return \stored_file
     */
    protected function make_file(
        int $contextid,
        string $filename,
        string $content,
        string $component = 'mod_label',
        string $filearea = 'intro'
    ): \stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea'  => $filearea,
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $content);
    }

    /**
     * The image-only filter excludes non-image files.
     */
    public function test_imageonly_filter(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $this->make_file($context->id, 'pic.jpg', 'JPEGDATA');
        $this->make_file($context->id, 'notes.txt', 'plain text');

        // Scope to our own component/area so unrelated site files cannot affect
        // the count.
        $matcher = new matcher(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            false
        );
        $estimate = $matcher->estimate();
        $this->assertSame(1, $estimate['count']);
    }

    /**
     * Course scope restricts matches to files inside the given course.
     */
    public function test_course_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $systemcontext = \context_system::instance();

        $this->make_file($coursecontext->id, 'incourse.png', 'A');
        $this->make_file($systemcontext->id, 'outside.png', 'B');

        $matcher = new matcher([
            'imageonly'  => true,
            'component'  => 'mod_label',
            'filearea'   => 'intro',
            'courseids'  => [$course->id],
        ], false);
        $estimate = $matcher->estimate();
        $this->assertSame(1, $estimate['count']);
    }

    /**
     * De-duplication collapses identical content to one match.
     */
    public function test_dedupe(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        // Same content, different names -> same content hash.
        $this->make_file($context->id, 'one.png', 'IDENTICAL');
        $this->make_file($context->id, 'two.png', 'IDENTICAL');

        $scope = ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'];
        $nodedupe = (new matcher($scope, false))->estimate();
        $this->assertSame(2, $nodedupe['count']);

        $dedupe = (new matcher($scope, true))->estimate();
        $this->assertSame(1, $dedupe['count']);
    }

    /**
     * The filename pattern uses * as a wildcard.
     */
    public function test_filename_pattern(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $this->make_file($context->id, 'banner-home.jpg', 'A');
        $this->make_file($context->id, 'logo.jpg', 'B');

        $matcher = new matcher([
            'imageonly'       => true,
            'component'       => 'mod_label',
            'filearea'        => 'intro',
            'filenamepattern' => 'banner*',
        ], false);
        $estimate = $matcher->estimate();
        $this->assertSame(1, $estimate['count']);
    }

    /**
     * The recordset returns the matched rows.
     */
    public function test_recordset(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $this->make_file($context->id, 'a.png', 'A');
        $this->make_file($context->id, 'b.png', 'B');

        $matcher = new matcher(
            ['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro'],
            false
        );
        $rs = $matcher->get_recordset();
        $names = [];
        foreach ($rs as $row) {
            $names[] = $row->filename;
        }
        $rs->close();
        sort($names);
        $this->assertSame(['a.png', 'b.png'], $names);
    }
}
