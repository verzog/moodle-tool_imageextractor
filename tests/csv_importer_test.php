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
 * Tests for the CSV importer.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the CSV importer.
 *
 * @covers \tool_imageextractor\csv_importer
 */
final class csv_importer_test extends \advanced_testcase {
    /**
     * Header names are normalised and rows mapped.
     */
    public function test_parse_rows(): void {
        $csv = "Course ID,Filename\n5,a.jpg\n6,b.png\n";
        $rows = csv_importer::parse_rows($csv);
        $this->assertCount(2, $rows);
        $this->assertSame('5', $rows[0]['courseid']);
        $this->assertSame('a.jpg', $rows[0]['filename']);
    }

    /**
     * Scope mode resolves course shortnames and ids to course ids.
     */
    public function test_scope_mode(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'SC1']);

        $rows = csv_importer::parse_rows("courseid\n{$course->id}\nSC1\nNOPE\n");
        $result = csv_importer::to_criteria($rows, 'scope');

        $this->assertContains((int) $course->id, $result['criteria']['courseids']);
        // Duplicate resolution (id and shortname both point at the same course).
        $this->assertCount(1, $result['criteria']['courseids']);
        // The unresolved row produced a warning.
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * Match mode separates filenames from content hashes.
     */
    public function test_match_mode(): void {
        $hash = str_repeat('a', 40);
        $rows = csv_importer::parse_rows("value\nlogo.png\n{$hash}\n");
        $result = csv_importer::to_criteria($rows, 'match');

        $this->assertContains('logo.png', $result['criteria']['filenames']);
        $this->assertContains($hash, $result['criteria']['contenthashes']);
    }

    /**
     * Per-row criteria mode turns each row into a criteria group.
     */
    public function test_criteria_mode(): void {
        $csv = "component,filename\nmod_forum,*.jpg\nmod_label,banner*\n";
        $rows = csv_importer::parse_rows($csv);
        $result = csv_importer::to_criteria($rows, 'criteria');

        $this->assertCount(2, $result['criteria']['rows']);
        $this->assertSame('mod_forum', $result['criteria']['rows'][0]['component']);
        $this->assertSame('*.jpg', $result['criteria']['rows'][0]['filenamepattern']);
    }
}
