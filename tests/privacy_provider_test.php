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
 * Tests for the privacy provider's handling of captured uploader data.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use tool_imageextractor\privacy\provider;

/**
 * The captured author name is personal data, so it must be cleared together
 * with the uploader attribution on erasure.
 *
 * @covers \tool_imageextractor\privacy\provider
 */
final class privacy_provider_test extends \advanced_testcase {
    /**
     * Insert a job and one captured item attributed to a user.
     *
     * @param int $uploaderid
     * @param string $author
     * @return int The item id.
     */
    protected function make_item(int $uploaderid, string $author): int {
        global $DB;
        $jobid = $DB->insert_record('tool_imageextractor_job', (object) [
            'name' => 'J', 'jobtype' => 'extract', 'status' => 'completed',
            'csvmode' => 'none', 'usermodified' => $uploaderid,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        return (int) $DB->insert_record('tool_imageextractor_item', (object) [
            'jobid' => $jobid, 'fileid' => 1, 'contenthash' => str_repeat('a', 40),
            'filename' => 'p.png', 'contextid' => \context_system::instance()->id,
            'filepath' => '/', 'outputname' => 'p.png', 'uploaderid' => $uploaderid,
            'author' => $author,
        ]);
    }

    /**
     * Deleting one user's data clears the captured author with the uploaderid.
     */
    public function test_delete_for_user_clears_author(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $itemid = $this->make_item((int) $user->id, $user->firstname . ' ' . $user->lastname);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'tool_imageextractor',
            [\context_system::instance()->id]
        ));

        $item = $DB->get_record('tool_imageextractor_item', ['id' => $itemid]);
        $this->assertSame(0, (int) $item->uploaderid);
        $this->assertNull($item->author);
    }

    /**
     * Deleting a set of users clears the captured author for each.
     */
    public function test_delete_for_users_clears_author(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $itemid = $this->make_item((int) $user->id, 'Some Name');

        $userlist = new approved_userlist(
            \context_system::instance(),
            'tool_imageextractor',
            [(int) $user->id]
        );
        provider::delete_data_for_users($userlist);

        $item = $DB->get_record('tool_imageextractor_item', ['id' => $itemid]);
        $this->assertSame(0, (int) $item->uploaderid);
        $this->assertNull($item->author);
    }

    /**
     * A context-wide purge clears every captured author.
     */
    public function test_delete_all_clears_author(): void {
        global $DB;
        $this->resetAfterTest();
        $itemid = $this->make_item(42, 'Captured Name');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $item = $DB->get_record('tool_imageextractor_item', ['id' => $itemid]);
        $this->assertSame(0, (int) $item->uploaderid);
        $this->assertNull($item->author);
    }
}
