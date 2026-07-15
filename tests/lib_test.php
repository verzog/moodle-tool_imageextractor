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
     * The criteria summary lists the selection method, the resolved scope names
     * and every option that was set (and only those).
     */
    public function test_criteria_rows(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/admin/tool/imageextractor/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'BIO101']);
        $criteria = [
            'imageonly'          => true,
            'courseids'          => [(int) $course->id],
            'modname'            => 'page',
            'modinstancepattern' => 'Lesson 1*',
            'filenamepattern'    => '*.jpg',
        ];
        $jobid = (int) $DB->insert_record('tool_imageextractor_job', (object) [
            'name' => 'J', 'jobtype' => '', 'status' => 'draft', 'csvmode' => 'none',
            'criteria' => json_encode($criteria), 'missingonly' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);

        $map = [];
        foreach (tool_imageextractor_criteria_rows($job) as $row) {
            $map[$row[0]] = (string) $row[1];
        }

        $this->assertSame(
            get_string('csvmode_none', 'tool_imageextractor'),
            $map[get_string('csvmode', 'tool_imageextractor')]
        );
        $this->assertStringContainsString('BIO101', $map[get_string('courses', 'tool_imageextractor')]);
        $this->assertSame(
            get_string('modulename', 'mod_page'),
            $map[get_string('modtype', 'tool_imageextractor')]
        );
        $this->assertSame('Lesson 1*', $map[get_string('modinstancepattern', 'tool_imageextractor')]);
        $this->assertSame('*.jpg', $map[get_string('filenamepattern', 'tool_imageextractor')]);
        $this->assertSame(get_string('yes'), $map[get_string('missingonly', 'tool_imageextractor')]);
        // An option that was not set does not appear.
        $this->assertArrayNotHasKey(get_string('categories', 'tool_imageextractor'), $map);
    }

    /**
     * An unknown course id degrades to "#id" rather than erroring.
     */
    public function test_criteria_rows_unknown_course(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/admin/tool/imageextractor/lib.php');
        $this->resetAfterTest();

        $jobid = (int) $DB->insert_record('tool_imageextractor_job', (object) [
            'name' => 'J', 'jobtype' => '', 'status' => 'draft', 'csvmode' => 'none',
            'criteria' => json_encode(['courseids' => [999999]]),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);

        $map = [];
        foreach (tool_imageextractor_criteria_rows($job) as $row) {
            $map[$row[0]] = (string) $row[1];
        }
        $this->assertSame('#999999', $map[get_string('courses', 'tool_imageextractor')]);
    }
}
