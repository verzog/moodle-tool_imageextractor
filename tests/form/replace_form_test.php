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
 * Tests for the Replace action panel form.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

/**
 * Submission tests for replace_form.
 *
 * These run the real validation via a mocked submission. That matters beyond
 * the assertions: validation() used to call get_new_filename(), which calls
 * is_validated(), which runs validation() again - infinite recursion that
 * pinned live web requests on CPU. Any regression makes these tests hang
 * rather than pass.
 *
 * @covers \tool_imageextractor\form\replace_form
 */
final class replace_form_test extends \advanced_testcase {
    /**
     * Build the form against a mocked submission.
     *
     * @param array $submission Simulated submitted data.
     * @param array $customdata Form custom data.
     * @return replace_form
     */
    protected function submitted_form(array $submission, array $customdata): replace_form {
        replace_form::mock_submit($submission);
        return new replace_form('/admin/tool/imageextractor/view.php', $customdata);
    }

    /**
     * A single-mode submission with no upload and no stored source is rejected
     * (and, implicitly, validation terminates instead of recursing).
     */
    public function test_single_mode_requires_an_upload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = $this->submitted_form([
            'id'              => 1,
            'replacemode'     => 'single',
            'replacementfile' => file_get_unused_draft_itemid(),
            'replacementzip'  => file_get_unused_draft_itemid(),
            'backup'          => 1,
            'replacesubmit'   => 'Continue',
        ], ['id' => 1, 'storedreplacemode' => '', 'hasstoredsource' => false]);

        $this->assertNull($form->get_data());
    }

    /**
     * An uploaded draft file satisfies the requirement.
     */
    public function test_single_mode_accepts_an_upload(): void {
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
            'filename'  => 'new.png',
        ], 'NEW');

        $form = $this->submitted_form([
            'id'              => 1,
            'replacemode'     => 'single',
            'replacementfile' => $draftid,
            'replacementzip'  => file_get_unused_draft_itemid(),
            'backup'          => 1,
            'replacesubmit'   => 'Continue',
        ], ['id' => 1, 'storedreplacemode' => '', 'hasstoredsource' => false]);

        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame('single', $data->replacemode);
    }

    /**
     * An existing job with a stored source for the chosen mode may resubmit
     * without a fresh upload; choosing the other mode still requires one.
     */
    public function test_stored_source_waives_upload_for_matching_mode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $submission = [
            'id'              => 1,
            'replacemode'     => 'single',
            'replacementfile' => file_get_unused_draft_itemid(),
            'replacementzip'  => file_get_unused_draft_itemid(),
            'backup'          => 1,
            'replacesubmit'   => 'Continue',
        ];

        $form = $this->submitted_form(
            $submission,
            ['id' => 1, 'storedreplacemode' => 'single', 'hasstoredsource' => true]
        );
        $this->assertNotNull($form->get_data());

        // Same stored source, but the submission switches to zip: the stale
        // single-image source must not satisfy a zip run.
        $submission['replacemode'] = 'zip';
        $form = $this->submitted_form(
            $submission,
            ['id' => 1, 'storedreplacemode' => 'single', 'hasstoredsource' => true]
        );
        $this->assertNull($form->get_data());
    }
}
