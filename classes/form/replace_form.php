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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Collects the replacement source (a single image or a ZIP matched by
 * filename) and the backup flag for overwriting the already-matched files.
 * This panel is only ever built for a site administrator with the replace
 * feature enabled; the results page enforces the same rule server-side.
 *
 * Expected custom data:
 * - id (int) The job id.
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

        $mode = $data['replacemode'] ?? 'single';
        if ($mode === 'zip') {
            if (!$this->get_new_filename('replacementzip')) {
                $errors['replacementzip'] = get_string('errornoreplacement', 'tool_imageextractor');
            }
        } else {
            if (!$this->get_new_filename('replacementfile')) {
                $errors['replacementfile'] = get_string('errornoreplacement', 'tool_imageextractor');
            }
        }

        return $errors;
    }
}
