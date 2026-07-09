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
 * Create / edit an extraction job.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for defining an extraction job's criteria, CSV, naming rule and output.
 */
class job_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

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

        // Criteria.
        $mform->addElement('header', 'criteriaheader', get_string('criteria', 'tool_imageextractor'));
        criteria_fields::add($mform);

        // Match estimate, kept inside the (expanded) criteria section so the
        // live figure is visible without opening a collapsed fieldset. The
        // no-submit button recomputes server-side without saving; the inline
        // region is updated live by the estimate AMD module and falls back to
        // the button when JavaScript is unavailable.
        $mform->addElement('submit', 'estimatematches', get_string('estimatematches', 'tool_imageextractor'));
        $mform->registerNoSubmitButton('estimatematches');
        $mform->addElement(
            'static',
            'estimatelive',
            get_string('estimatelive', 'tool_imageextractor'),
            \html_writer::span('—', 'tool_imageextractor-estimate', ['data-region' => 'tool_imageextractor-estimate'])
        );
        $mform->addHelpButton('estimatelive', 'estimatelive', 'tool_imageextractor');

        // CSV.
        $mform->addElement('header', 'csvheader', get_string('csvupload', 'tool_imageextractor'));

        $modes = [
            'none'     => get_string('csvmode_none', 'tool_imageextractor'),
            'scope'    => get_string('csvmode_scope', 'tool_imageextractor'),
            'match'    => get_string('csvmode_match', 'tool_imageextractor'),
            'criteria' => get_string('csvmode_criteria', 'tool_imageextractor'),
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

        // Output.
        $mform->addElement('header', 'outputheader', get_string('output', 'tool_imageextractor'));

        $mform->addElement(
            'text',
            'namingrule',
            get_string('namingrule', 'tool_imageextractor'),
            ['size' => 60]
        );
        $mform->setType('namingrule', PARAM_TEXT);
        $mform->setDefault('namingrule', '{originalname}');
        $mform->addHelpButton('namingrule', 'namingrule', 'tool_imageextractor');

        $mform->addElement(
            'advcheckbox',
            'dedupe',
            get_string('dedupe', 'tool_imageextractor'),
            get_string('dedupe_help', 'tool_imageextractor')
        );
        $mform->setDefault('dedupe', 1);

        $defaultvolmb = (int) get_config('tool_imageextractor', 'default_volume_mb');
        $mform->addElement(
            'text',
            'volumemb',
            get_string('volumemb', 'tool_imageextractor'),
            ['size' => 10]
        );
        $mform->setType('volumemb', PARAM_INT);
        $mform->setDefault('volumemb', $defaultvolmb ?: 2048);
        $mform->addHelpButton('volumemb', 'volumemb', 'tool_imageextractor');

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

        if (isset($data['volumemb']) && (int) $data['volumemb'] < 1) {
            $errors['volumemb'] = get_string('errorvolumesize', 'tool_imageextractor');
        }

        return $errors;
    }
}
