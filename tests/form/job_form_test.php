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
 * Tests for the job criteria form.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

/**
 * Submission tests for job_form's CSV requirement.
 *
 * These run the real validation via a mocked submission. That matters beyond
 * the assertions: the CSV check used to call get_new_filename(), which calls
 * is_validated(), which runs validation() again - infinite recursion that
 * pinned live web requests on CPU. Any regression makes these tests hang
 * rather than pass.
 *
 * @covers \tool_imageextractor\form\job_form
 */
final class job_form_test extends \advanced_testcase {
    /**
     * Build the form against a mocked submission.
     *
     * @param array $submission Simulated submitted data.
     * @return job_form
     */
    protected function submitted_form(array $submission): job_form {
        job_form::mock_submit($submission);
        return new job_form('/admin/tool/imageextractor/edit.php', ['id' => 0]);
    }

    /**
     * Choosing a CSV mode without uploading a CSV is rejected (and validation
     * terminates instead of recursing).
     */
    public function test_csv_mode_requires_a_csv(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = $this->submitted_form([
            'id'               => 0,
            'name'             => 'CSV job',
            'csvmode'          => 'match',
            'csvfile'          => file_get_unused_draft_itemid(),
            'submitbutton'     => 'Save',
        ]);

        $this->assertNull($form->get_data());
    }

    /**
     * An uploaded CSV satisfies the requirement.
     */
    public function test_csv_mode_accepts_a_csv(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'match.csv',
        ], "filename\nlogo.png\n");

        $form = $this->submitted_form([
            'id'               => 0,
            'name'             => 'CSV job',
            'csvmode'          => 'match',
            'csvfile'          => $draftid,
            'submitbutton'     => 'Save',
        ]);

        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame('match', $data->csvmode);
    }
}
