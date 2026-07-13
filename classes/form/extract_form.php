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
 * The Extract action panel on a job's results page.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Collects the output options for packing the already-matched files into
 * downloadable ZIP volumes: the output-name rule and the volume size.
 *
 * Expected custom data:
 * - id (int) The job id.
 */
class extract_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'text',
            'namingrule',
            get_string('namingrule', 'tool_imageextractor'),
            ['size' => 60]
        );
        $mform->setType('namingrule', PARAM_TEXT);
        $mform->setDefault('namingrule', '{originalname}');
        $mform->addHelpButton('namingrule', 'namingrule', 'tool_imageextractor');

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

        $mform->addElement('submit', 'extractsubmit', get_string('extractdownload', 'tool_imageextractor'));
    }

    /**
     * Validate the submitted output options.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (isset($data['volumemb']) && (int) $data['volumemb'] < 1) {
            $errors['volumemb'] = get_string('errorvolumesize', 'tool_imageextractor');
        }

        return $errors;
    }
}
