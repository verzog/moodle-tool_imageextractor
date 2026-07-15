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
 * Tests for the HTML alt-text locator/reader/writer.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the HTML alt-text locator/reader/writer.
 *
 * @covers \tool_imageextractor\htmllocator
 */
final class htmllocator_test extends \advanced_testcase {
    /**
     * The alt text of a matching <img> is read; a percent-encoded src still
     * matches, and a non-matching image is ignored.
     */
    public function test_extract_alts(): void {
        $html = '<p><img src="@@PLUGINFILE@@/My%20Pic.png" alt="A red car"></p>'
            . '<img src="@@PLUGINFILE@@/other.png" alt="Something else">';
        $this->assertSame(['A red car'], htmllocator::extract_alts($html, 'My Pic.png'));
        $this->assertSame(['Something else'], htmllocator::extract_alts($html, 'other.png'));
        $this->assertSame([], htmllocator::extract_alts($html, 'missing.png'));
    }

    /**
     * An image with no alt attribute reads as an empty description.
     */
    public function test_extract_alts_missing_attribute(): void {
        $html = '<img src="@@PLUGINFILE@@/pic.png">';
        $this->assertSame([''], htmllocator::extract_alts($html, 'pic.png'));
    }

    /**
     * Setting alt replaces an existing value on the matching image only, and
     * HTML-encodes the new value.
     */
    public function test_set_alt_replaces_existing(): void {
        $html = '<img src="@@PLUGINFILE@@/pic.png" alt="old" width="10">'
            . '<img src="@@PLUGINFILE@@/keep.png" alt="untouched">';
        [$new, $changed] = htmllocator::set_alt($html, 'pic.png', 'A "new" <caption>');
        $this->assertSame(1, $changed);
        $this->assertStringContainsString('alt="A &quot;new&quot; &lt;caption&gt;"', $new);
        // The other image and the surrounding attributes are left intact.
        $this->assertStringContainsString('alt="untouched"', $new);
        $this->assertStringContainsString('width="10"', $new);
    }

    /**
     * Setting alt on an image that has none inserts the attribute, keeping the
     * tag otherwise byte-identical (including a self-closing form).
     */
    public function test_set_alt_inserts_when_missing(): void {
        [$new, $changed] = htmllocator::set_alt('<img src="@@PLUGINFILE@@/pic.png" />', 'pic.png', 'desc');
        $this->assertSame(1, $changed);
        $this->assertStringContainsString('src="@@PLUGINFILE@@/pic.png"', $new);
        $this->assertStringContainsString('alt="desc"', $new);

        [$new2, $changed2] = htmllocator::set_alt('<img src="@@PLUGINFILE@@/pic.png">', 'pic.png', 'desc');
        $this->assertSame(1, $changed2);
        $this->assertStringContainsString('alt="desc"', $new2);
    }

    /**
     * A file name that is not referenced leaves the HTML unchanged.
     */
    public function test_set_alt_no_match(): void {
        $html = '<img src="@@PLUGINFILE@@/pic.png" alt="old">';
        [$new, $changed] = htmllocator::set_alt($html, 'absent.png', 'desc');
        $this->assertSame(0, $changed);
        $this->assertSame($html, $new);
    }

    /**
     * locate() resolves a page's content field from an embedded image's file
     * area, and the round-trip read/write updates the live page content.
     */
    public function test_locate_and_write_page_content(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course'        => $course->id,
            'content'       => '<p><img src="@@PLUGINFILE@@/diagram.png" alt="old alt"></p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $modcontext = \context_module::instance($page->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $modcontext->id,
            'component' => 'mod_page',
            'filearea'  => 'content',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'diagram.png',
        ], 'PNGDATA');

        // The item as the matcher would record it for this embedded file.
        $item = (object) [
            'contextid'  => $modcontext->id,
            'component'  => 'mod_page',
            'filearea'   => 'content',
            'fileitemid' => 0,
            'filename'   => 'diagram.png',
        ];

        $locations = htmllocator::locate($item);
        $this->assertCount(1, $locations);
        $this->assertSame('page', $locations[0]->table);
        $this->assertSame('content', $locations[0]->column);
        $this->assertSame((int) $page->id, $locations[0]->id);
        $this->assertSame(['old alt'], htmllocator::extract_alts($locations[0]->html, 'diagram.png'));

        [$newhtml, $changed] = htmllocator::set_alt($locations[0]->html, 'diagram.png', 'new alt');
        $this->assertSame(1, $changed);
        $DB->set_field('page', 'content', $newhtml, ['id' => $page->id]);

        $stored = $DB->get_field('page', 'content', ['id' => $page->id]);
        $this->assertStringContainsString('alt="new alt"', $stored);
    }
}
