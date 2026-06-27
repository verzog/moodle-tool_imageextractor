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
 * Tests for the naming-rule renderer.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the naming-rule renderer.
 *
 * @covers \tool_imageextractor\naming
 */
final class naming_test extends \advanced_testcase {
    /**
     * Placeholders are substituted and the extension preserved.
     */
    public function test_render_basic(): void {
        $name = naming::render('{courseshortname}_{seq}_{originalbase}', [
            'originalname'    => 'photo.JPG',
            'courseshortname' => 'BIO101',
            'seq'             => 3,
        ]);
        $this->assertSame('BIO101_3_photo.JPG', $name);
    }

    /**
     * A template without an extension still gets the original one appended.
     */
    public function test_render_appends_extension(): void {
        $name = naming::render('{fileid}', [
            'originalname' => 'picture.png',
            'fileid'       => 42,
        ]);
        $this->assertSame('42.png', $name);
    }

    /**
     * Unsafe characters in substituted values are cleaned away.
     */
    public function test_render_cleans_values(): void {
        $name = naming::render('{coursename}_{originalbase}', [
            'originalname' => 'a.gif',
            'coursename'   => 'Term 1/2024',
        ]);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringEndsWith('.gif', $name);
    }

    /**
     * Empty results fall back to a sensible name.
     */
    public function test_render_empty_fallback(): void {
        $name = naming::render('{unknownplaceholder}', [
            'originalname' => 'x.jpg',
            'fileid'       => 7,
        ]);
        $this->assertStringStartsWith('image-7', $name);
    }

    /**
     * Duplicate names get a numeric suffix.
     */
    public function test_ensure_unique(): void {
        $seen = [];
        $this->assertSame('a.jpg', naming::ensure_unique('a.jpg', $seen));
        $this->assertSame('a_1.jpg', naming::ensure_unique('a.jpg', $seen));
        $this->assertSame('a_2.jpg', naming::ensure_unique('a.jpg', $seen));
        $this->assertSame('b.jpg', naming::ensure_unique('b.jpg', $seen));
    }
}
