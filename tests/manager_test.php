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
            // Duplicates and a 0 (the site course) must be cleaned out.
            'courseids'   => [$courseone->id, $coursetwo->id, $courseone->id, 0],
            'categoryids' => [$category->id, $category->id],
        ];

        $result = manager::save_job($data);
        $job = manager::get_job($result['id']);
        $criteria = manager::decode_criteria($job);

        $this->assertSame([(int) $courseone->id, (int) $coursetwo->id], $criteria['courseids']);
        $this->assertSame([(int) $category->id], $criteria['categoryids']);
        // A new job is criteria-only: its type is unset until an action is
        // chosen from the results page.
        $this->assertSame('', $job->jobtype);
    }

    /**
     * With no scope chosen, the criteria carry empty scope lists (whole-site
     * search) rather than stale or missing keys.
     */
    public function test_save_job_without_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = (object) [
            'name' => 'Unscoped job',
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
     * Choosing the Extract action records the naming rule and volume size and
     * sets the job's type to extract, without touching its criteria.
     */
    public function test_set_extract_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'To extract']);
        $job = manager::get_job($result['id']);
        $this->assertSame('', $job->jobtype);

        manager::set_extract_action($result['id'], '{courseshortname}_{originalname}', 512);
        $job = manager::get_job($result['id']);

        $this->assertSame('extract', $job->jobtype);
        $this->assertSame('{courseshortname}_{originalname}', $job->namingrule);
        $this->assertSame(512 * 1024 * 1024, (int) $job->volumesize);
    }

    /**
     * Choosing the Replace action records the mode and backup flag, stores the
     * uploaded replacement source, and sets the job's type to replace.
     */
    public function test_set_replace_action(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'To replace']);

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'new.png',
        ], 'NEW');

        manager::set_replace_action($result['id'], (object) [
            'replacemode'     => 'single',
            'backup'          => 1,
            'replacementfile' => $draftid,
        ]);
        $job = manager::get_job($result['id']);

        $this->assertSame('replace', $job->jobtype);
        $this->assertSame('single', $job->replacemode);
        $this->assertSame(1, (int) $job->backup);
        // The replacement source is stored in the job's replacement area.
        $stored = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            manager::COMPONENT,
            'replacement',
            $result['id'],
            'id',
            false
        );
        $this->assertCount(1, $stored);
    }

    /**
     * Re-choosing Replace on a job that already has a stored single source,
     * without uploading a new file, keeps the stored image rather than wiping it
     * with the empty filepicker draft (which would leave the apply phase nothing
     * to replace with).
     */
    public function test_set_replace_action_reuses_stored_single_source(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'Reuse source']);

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'orig.png',
        ], 'ORIG');

        // First submission stores the source.
        manager::set_replace_action($result['id'], (object) [
            'replacemode'     => 'single',
            'backup'          => 1,
            'replacementfile' => $draftid,
        ]);

        // Second submission reuses it: a fresh, empty filepicker draft.
        $emptydraft = file_get_unused_draft_itemid();
        manager::set_replace_action($result['id'], (object) [
            'replacemode'     => 'single',
            'backup'          => 1,
            'replacementfile' => $emptydraft,
        ]);

        $stored = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            manager::COMPONENT,
            'replacement',
            $result['id'],
            'id',
            false
        );
        $this->assertCount(1, $stored);
        $this->assertSame('ORIG', reset($stored)->get_content());
    }

    /**
     * A per-row-criteria CSV replace job is refused by the queue path itself
     * (not just the results page), so it cannot be applied from the CLI.
     */
    public function test_queue_job_rejects_criteria_csv_replace(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'Criteria replace']);
        $DB->set_field('tool_imageextractor_job', 'jobtype', 'replace', ['id' => $result['id']]);
        $DB->set_field('tool_imageextractor_job', 'csvmode', 'criteria', ['id' => $result['id']]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/criteria/i');
        manager::queue_job($result['id']);
    }

    /**
     * Any direct extract run through queue_job() normalises de-duplication off,
     * even for an existing extract job whose stored dedupe flag is still 1, so
     * the packing task packs every matched item.
     */
    public function test_queue_job_disables_dedupe_for_extract(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'Extract dedupe']);
        // Simulate an existing/upgraded extract job carrying the old default.
        $DB->set_field('tool_imageextractor_job', 'jobtype', 'extract', ['id' => $result['id']]);
        $DB->set_field('tool_imageextractor_job', 'dedupe', 1, ['id' => $result['id']]);

        manager::queue_job($result['id']);

        $this->assertSame(0, (int) $DB->get_field('tool_imageextractor_job', 'dedupe', ['id' => $result['id']]));
    }

    /**
     * Deleting a job with no prepared items removes it inline.
     */
    public function test_delete_job_inline_when_light(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'Light delete']);

        $this->assertFalse(manager::delete_job($result['id']));
        $this->assertFalse($DB->record_exists('tool_imageextractor_job', ['id' => $result['id']]));
    }

    /**
     * Deleting a job that holds prepared item rows is deferred: the web request
     * only parks the job as clearing (deleting millions of rows inline would
     * time out), and the background task chunks through the items before
     * removing the job definition.
     */
    public function test_delete_job_defers_heavy_clear(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manager::save_job((object) ['name' => 'Heavy delete']);
        $DB->insert_record('tool_imageextractor_item', (object) [
            'jobid'       => $result['id'],
            'fileid'      => 1,
            'contenthash' => sha1('x'),
            'filename'    => 'x.png',
            'outputname'  => 'x.png',
        ]);

        $this->assertTrue(manager::delete_job($result['id']));
        // Still present, parked for the background clear.
        $this->assertSame(
            manager::STATUS_CLEARING,
            $DB->get_field('tool_imageextractor_job', 'status', ['id' => $result['id']])
        );

        $this->runAdhocTasks('\tool_imageextractor\task\reset_job');

        $this->assertFalse($DB->record_exists('tool_imageextractor_item', ['jobid' => $result['id']]));
        $this->assertFalse($DB->record_exists('tool_imageextractor_job', ['id' => $result['id']]));
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

    /**
     * The batch size falls back to a gentle default and honours a configured
     * value, never returning less than one.
     */
    public function test_batch_size(): void {
        $this->resetAfterTest();

        $this->assertSame(50, manager::batch_size());

        set_config('batch_size', 200, 'tool_imageextractor');
        $this->assertSame(200, manager::batch_size());

        // A nonsensical value falls back to the default rather than stalling.
        set_config('batch_size', 0, 'tool_imageextractor');
        $this->assertSame(50, manager::batch_size());
    }

    /**
     * The throttle delay defaults to a gentle pause when never configured, but
     * an explicit 0 (opt out) is respected rather than treated as unset.
     */
    public function test_throttle_delay(): void {
        $this->resetAfterTest();

        // Never configured: the gentle default applies.
        $this->assertSame(20, manager::throttle_delay());

        set_config('throttle_delay', 45, 'tool_imageextractor');
        $this->assertSame(45, manager::throttle_delay());

        // Explicit 0 means "no throttle", not "unset".
        set_config('throttle_delay', 0, 'tool_imageextractor');
        $this->assertSame(0, manager::throttle_delay());
    }
}
