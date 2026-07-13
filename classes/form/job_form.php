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
 * Create / edit a job's file selection (criteria only).
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
 * The job definition form. A job now only captures which files it selects; the
 * extract-versus-replace action, and the options that go with it, are chosen
 * later from the results page once an analyse has confirmed the matches.
 *
 * Expected custom data:
 * - id (int) Job id when editing, 0 for a new job.
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

        // Criteria: how the files are chosen.
        $mform->addElement('header', 'criteriaheader', get_string('criteria', 'tool_imageextractor'));
        // How the files are chosen comes first: by the criteria fields below,
        // or driven by an uploaded CSV. A match-list CSV nominates exact files,
        // so choosing it hides (and the save ignores) the criteria fields -
        // a stale course or MIME filter must never silently exclude a
        // nominated file.
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

        criteria_fields::add($mform);

        // A match refinement kept with the criteria: limit the selection to
        // files whose stored content is missing or unreadable. Available for
        // match lists too - fixing only the broken ones of the nominated files
        // is a legitimate combination.
        $mform->addElement(
            'advcheckbox',
            'missingonly',
            get_string('missingonly', 'tool_imageextractor'),
            get_string('missingonly_help', 'tool_imageextractor')
        );

        $this->add_action_buttons();
    }

    /**
     * Validate the submitted criteria.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $errors += criteria_fields::validate($data);

        // Every CSV-driven mode needs a CSV; without one a match-mode job
        // would have nothing nominating its files. When editing, the stored
        // CSV is preloaded into the draft area, which satisfies this. The
        // draft area is inspected directly, NOT via get_new_filename(): that
        // method calls is_validated(), which runs this validation() again -
        // infinite recursion that spins the request whenever a CSV mode is
        // chosen.
        $csvmodechosen = ($data['csvmode'] ?? 'none') !== 'none';
        if ($csvmodechosen && !manager::draft_has_file((int) ($data['csvfile'] ?? 0))) {
            $errors['csvfile'] = get_string('errorcsvrequired', 'tool_imageextractor');
        }

        return $errors;
    }
}
