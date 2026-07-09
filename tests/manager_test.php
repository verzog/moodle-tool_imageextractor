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
 * Tests for the job manager.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the job manager, focused on how submitted form data becomes the
 * stored job criteria.
 *
 * @covers \tool_imageextractor\manager
 */
final class manager_test extends \advanced_testcase {
    /**
     * The course and category scope pickers are folded into the job criteria,
     * de-duplicated and with 0/negative ids dropped.
     */
    public function test_save_job_stores_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_category();

        $data = (object) [
            'name'        => 'Scoped job',
            'jobtype'     => 'extract',
            // Duplicates and a 0 (the site course) must be cleaned out.
            'courseids'   => [$courseone->id, $coursetwo->id, $courseone->id, 0],
            'categoryids' => [$category->id, $category->id],
        ];

        $result = manager::save_job($data);
        $job = manager::get_job($result['id']);
        $criteria = manager::decode_criteria($job);

        $this->assertSame([(int) $courseone->id, (int) $coursetwo->id], $criteria['courseids']);
        $this->assertSame([(int) $category->id], $criteria['categoryids']);
        $this->assertSame('extract', $job->jobtype);
    }

    /**
     * With no scope chosen, the criteria carry empty scope lists (whole-site
     * search) rather than stale or missing keys.
     */
    public function test_save_job_without_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = (object) [
            'name'    => 'Unscoped job',
            'jobtype' => 'extract',
        ];

        $result = manager::save_job($data);
        $criteria = manager::decode_criteria(manager::get_job($result['id']));

        $this->assertSame([], $criteria['courseids']);
        $this->assertSame([], $criteria['categoryids']);
    }

    /**
     * A match-list CSV nominates exact files: the criteria fields are ignored
     * (and imageonly disabled) so stale values can never silently exclude a
     * nominated file.
     */
    public function test_save_job_match_csv_ignores_criteria(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'match.csv',
        ], "filename\nlogo.png\nbanner.jpg\n");

        $data = (object) [
            'name'      => 'Nominated files',
            'jobtype'   => 'extract',
            'csvmode'   => 'match',
            'csvfile'   => $draftid,
            // These would silently narrow the nominated list; all ignored.
            'imageonly' => 1,
            'courseids' => [$course->id],
            'mimetypes' => 'image/png',
            'component' => 'mod_forum',
        ];

        $result = manager::save_job($data);
        $criteria = manager::decode_criteria(manager::get_job($result['id']));

        $this->assertSame(['logo.png', 'banner.jpg'], $criteria['filenames']);
        $this->assertFalse($criteria['imageonly']);
        $this->assertSame([], $criteria['courseids']);
        $this->assertSame([], $criteria['mimetypes']);
        $this->assertSame('', $criteria['component']);
    }

    /**
     * criteria_from_data maps raw form fields to criteria: kilobyte sizes are
     * converted to bytes, a comma-separated MIME list is split, and id lists
     * are cleaned - without any database access.
     */
    public function test_criteria_from_data(): void {
        $data = (object) [
            'imageonly'   => 1,
            'component'   => ' mod_forum ',
            'minsizekb'   => 10,
            'maxsizekb'   => 20,
            'mimetypes'   => 'image/png, image/jpeg ,',
            'courseids'   => [5, 5, 0, 7],
            'categoryids' => [3],
        ];

        $criteria = manager::criteria_from_data($data);

        $this->assertTrue($criteria['imageonly']);
        $this->assertSame('mod_forum', $criteria['component']);
        $this->assertSame(10 * 1024, $criteria['minsize']);
        $this->assertSame(20 * 1024, $criteria['maxsize']);
        $this->assertSame(['image/png', 'image/jpeg'], $criteria['mimetypes']);
        $this->assertSame([5, 7], $criteria['courseids']);
        $this->assertSame([3], $criteria['categoryids']);
    }
}
