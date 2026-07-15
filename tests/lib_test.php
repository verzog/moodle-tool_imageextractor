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
 * Tests for the plugin-level display helpers in lib.php.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the plugin-level display helpers in lib.php.
 *
 * @covers ::tool_imageextractor_criteria_rows
 */
final class lib_test extends \advanced_testcase {
    /**
     * Build a job from the given criteria (and optional column overrides) and
     * return its criteria summary as a label => value map.
     *
     * @param array $criteria Stored criteria array.
     * @param array $columns Extra tool_imageextractor_job column overrides.
     * @return array
     */
    protected function criteria_map(array $criteria, array $columns = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/admin/tool/imageextractor/lib.php');

        $jobid = (int) $DB->insert_record('tool_imageextractor_job', (object) array_merge([
            'name' => 'J', 'jobtype' => '', 'status' => 'draft', 'csvmode' => 'none',
            'criteria' => json_encode($criteria), 'timecreated' => time(), 'timemodified' => time(),
        ], $columns));
        $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);

        $map = [];
        foreach (tool_imageextractor_criteria_rows($job) as $row) {
            $map[$row[0]] = (string) $row[1];
        }
        return $map;
    }

    /**
     * Label helper.
     *
     * @param string $key
     * @return string
     */
    protected function label(string $key): string {
        return get_string($key, 'tool_imageextractor');
    }

    /**
     * The summary lists the selection method, the resolved scope names and
     * every option that was set (and only those).
     */
    public function test_criteria_rows(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'BIO101']);

        $map = $this->criteria_map([
            'imageonly'          => true,
            'courseids'          => [(int) $course->id],
            'modname'            => 'page',
            'modinstancepattern' => 'Lesson 1*',
            'filenamepattern'    => '*.jpg',
        ], ['missingonly' => 1]);

        $this->assertSame($this->label('csvmode_none'), $map[$this->label('csvmode')]);
        $this->assertStringContainsString('BIO101', $map[$this->label('courses')]);
        $this->assertSame(get_string('modulename', 'mod_page'), $map[$this->label('modtype')]);
        $this->assertSame('Lesson 1*', $map[$this->label('modinstancepattern')]);
        $this->assertSame('*.jpg', $map[$this->label('filenamepattern')]);
        $this->assertSame(get_string('yes'), $map[$this->label('missingonly')]);
        $this->assertArrayNotHasKey($this->label('categories'), $map);
    }

    /**
     * An unknown course id degrades to "#id" rather than erroring.
     */
    public function test_criteria_rows_unknown_course(): void {
        $this->resetAfterTest();
        $map = $this->criteria_map(['courseids' => [999999]]);
        $this->assertSame('#999999', $map[$this->label('courses')]);
    }

    /**
     * Criteria with no imageonly key report the filter as ON, matching the
     * matcher's default (an absent key means image-only is applied).
     */
    public function test_criteria_rows_imageonly_default(): void {
        $this->resetAfterTest();
        $map = $this->criteria_map(['filenamepattern' => '*.png']);
        $this->assertSame(get_string('yes'), $map[$this->label('imageonly')]);

        // Explicitly off is reported as off.
        $map = $this->criteria_map(['imageonly' => false, 'filenamepattern' => '*.png']);
        $this->assertSame(get_string('no'), $map[$this->label('imageonly')]);
    }

    /**
     * A match CSV that nominated nothing falls back to (and shows) the ordinary
     * criteria, rather than hiding them behind the match mode.
     */
    public function test_criteria_rows_empty_match_shows_criteria(): void {
        $this->resetAfterTest();
        // Match mode but no filenames/hashes: save_job leaves criteria in force.
        $map = $this->criteria_map(['imageonly' => true], ['csvmode' => 'match']);
        $this->assertSame(get_string('yes'), $map[$this->label('imageonly')]);
        $this->assertArrayNotHasKey($this->label('criteriamatchfiles'), $map);
    }

    /**
     * A scope CSV's uploader restriction (userids) is shown.
     */
    public function test_criteria_rows_user_scope(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);

        $map = $this->criteria_map(['userids' => [(int) $user->id]], ['csvmode' => 'scope']);
        $this->assertArrayHasKey($this->label('criteriausers'), $map);
        $this->assertStringContainsString('Ada Lovelace', $map[$this->label('criteriausers')]);
    }
}
