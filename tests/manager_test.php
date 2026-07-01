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
}
