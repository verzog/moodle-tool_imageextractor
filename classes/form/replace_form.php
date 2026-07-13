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
 * The Replace action panel on a job's results page.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

use tool_imageextractor\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Collects the replacement source (a single image or a ZIP matched by
 * filename) and the backup flag for overwriting the already-matched files.
 * This panel is only ever built for a site administrator with the replace
 * feature enabled; the results page enforces the same rule server-side.
 *
 * Expected custom data:
 * - id (int)                    The job id.
 * - storedreplacemode (string) Replace mode that already has a stored source.
 * - hasstoredsource   (bool)   Whether a replacement source is already stored.
 */
class replace_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $replacemodes = [
            'single' => get_string('replacemode_single', 'tool_imageextractor'),
            'zip'    => get_string('replacemode_zip', 'tool_imageextractor'),
        ];
        $mform->addElement('select', 'replacemode', get_string('replacemode', 'tool_imageextractor'), $replacemodes);
        $mform->setDefault('replacemode', 'single');
        $mform->addHelpButton('replacemode', 'replacemode', 'tool_imageextractor');

        $mform->addElement(
            'filepicker',
            'replacementfile',
            get_string('replacementfile', 'tool_imageextractor'),
            null,
            ['accepted_types' => ['web_image'], 'maxfiles' => 1]
        );
        $mform->hideIf('replacementfile', 'replacemode', 'eq', 'zip');

        $mform->addElement(
            'filepicker',
            'replacementzip',
            get_string('replacementzip', 'tool_imageextractor'),
            null,
            ['accepted_types' => ['.zip'], 'maxfiles' => 1]
        );
        $mform->hideIf('replacementzip', 'replacemode', 'eq', 'single');

        $mform->addElement(
            'advcheckbox',
            'backup',
            get_string('backup', 'tool_imageextractor'),
            get_string('backup_help', 'tool_imageextractor')
        );
        $mform->setDefault('backup', 1);

        $mform->addElement('submit', 'replacesubmit', get_string('replacecontinue', 'tool_imageextractor'));
    }

    /**
     * Validate that a replacement source for the chosen mode was uploaded.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // A replacement source is required, unless the job already has a stored
        // source FOR THE SELECTED MODE (an existing/upgraded replace job).
        // Switching, say, single -> zip without uploading a new archive must
        // not pass, or the job would run against the stale source (or nothing).
        $mode = $data['replacemode'] ?? 'single';
        $storedmode = $this->_customdata['storedreplacemode'] ?? '';
        $hasstoredsource = !empty($this->_customdata['hasstoredsource']);
        $hasstored = $hasstoredsource && $mode === $storedmode;

        // The upload is checked by inspecting the submitted draft area, NOT via
        // get_new_filename(): that method calls is_validated(), which runs this
        // validation() again - infinite recursion that pinned the web request
        // on CPU (and, holding the session lock, hung every other request from
        // the same user) whenever this panel was submitted.
        if ($mode === 'zip') {
            if (!manager::draft_has_file((int) ($data['replacementzip'] ?? 0)) && !$hasstored) {
                $errors['replacementzip'] = get_string('errornoreplacement', 'tool_imageextractor');
            }
        } else {
            if (!manager::draft_has_file((int) ($data['replacementfile'] ?? 0)) && !$hasstored) {
                $errors['replacementfile'] = get_string('errornoreplacement', 'tool_imageextractor');
            }
        }

        return $errors;
    }
}
