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
 * Create / edit a replace (restore) job.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for defining which files to replace and what to replace them with.
 */
class replace_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'jobtype', 'replace');
        $mform->setType('jobtype', PARAM_ALPHA);

        $mform->addElement(
            'text',
            'name',
            get_string('jobname', 'tool_imageextractor'),
            ['size' => 50, 'maxlength' => 255]
        );
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'description',
            get_string('jobdescription', 'tool_imageextractor'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('description', PARAM_TEXT);

        // Which files to target.
        $mform->addElement('header', 'criteriaheader', get_string('targetcriteria', 'tool_imageextractor'));
        criteria_fields::add($mform);

        $mform->addElement(
            'advcheckbox',
            'missingonly',
            get_string('missingonly', 'tool_imageextractor'),
            get_string('missingonly_help', 'tool_imageextractor')
        );

        // CSV refinement (optional).
        $modes = [
            'none'  => get_string('csvmode_none', 'tool_imageextractor'),
            'scope' => get_string('csvmode_scope', 'tool_imageextractor'),
            'match' => get_string('csvmode_match', 'tool_imageextractor'),
        ];
        $mform->addElement('select', 'csvmode', get_string('csvmode', 'tool_imageextractor'), $modes);
        $mform->setDefault('csvmode', 'none');
        $mform->addHelpButton('csvmode', 'csvmode', 'tool_imageextractor');

        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('csvfile', 'tool_imageextractor'),
            null,
            ['accepted_types' => ['.csv', '.txt'], 'maxfiles' => 1]
        );
        $mform->hideIf('csvfile', 'csvmode', 'eq', 'none');

        // What to replace them with.
        $mform->addElement('header', 'replacementheader', get_string('replacement', 'tool_imageextractor'));

        $replacemodes = [
            'single' => get_string('replacemode_single', 'tool_imageextractor'),
            'zip'    => get_string('replacemode_zip', 'tool_imageextractor'),
        ];
        $mform->addElement(
            'select',
            'replacemode',
            get_string('replacemode', 'tool_imageextractor'),
            $replacemodes
        );
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

        $this->add_action_buttons();
    }

    /**
     * Validate submitted data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $errors += criteria_fields::validate($data);

        // A replacement source is required, unless editing an existing job that
        // already has one stored.
        $hasstored = !empty($data['id']);
        if (($data['replacemode'] ?? 'single') === 'zip') {
            if (!$this->get_new_filename('replacementzip') && !$hasstored) {
                $errors['replacementzip'] = get_string('errornoreplacement', 'tool_imageextractor');
            }
        } else {
            if (!$this->get_new_filename('replacementfile') && !$hasstored) {
                $errors['replacementfile'] = get_string('errornoreplacement', 'tool_imageextractor');
            }
        }

        return $errors;
    }
}
