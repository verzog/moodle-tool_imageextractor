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
 * Create / edit an extraction or replace job.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * One form for both job types. A type selector at the top - extract (download
 * images) or replace (upload replacements) - drives hideIf rules so only the
 * sections relevant to the chosen type are shown: the output/naming section
 * for extract, the replacement-source section for replace.
 *
 * Expected custom data:
 * - id                (int)    Job id when editing, 0 for a new job.
 * - jobtype           (string) 'extract' or 'replace'; fixed when editing.
 * - allowreplace      (bool)   Whether this user may create replace jobs.
 * - storedreplacemode (string) Replace mode that already has a stored source.
 * - hasstoredsource   (bool)   Whether a replacement source is stored.
 */
class job_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $isedit = !empty($this->_customdata['id']);
        $jobtype = ($this->_customdata['jobtype'] ?? 'extract') === 'replace' ? 'replace' : 'extract';
        $allowreplace = !empty($this->_customdata['allowreplace']);
        // The replacement section only exists when this user could pick (or is
        // editing) a replace job; hideIf keeps it hidden while extract is the
        // selected type.
        $replaceavailable = $isedit ? ($jobtype === 'replace') : $allowreplace;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // What the job does: download (extract) or upload (replace). The type
        // is fixed once a job exists - its stored outputs and sources differ.
        if ($isedit || !$allowreplace) {
            $mform->addElement('hidden', 'jobtype', $jobtype);
            $mform->setType('jobtype', PARAM_ALPHA);
            if ($isedit) {
                $mform->addElement(
                    'static',
                    'jobtypedisplay',
                    get_string('jobtype', 'tool_imageextractor'),
                    get_string('jobtype_' . $jobtype, 'tool_imageextractor')
                );
            }
        } else {
            $mform->addElement('select', 'jobtype', get_string('jobtype', 'tool_imageextractor'), [
                'extract' => get_string('jobtypeoption_extract', 'tool_imageextractor'),
                'replace' => get_string('jobtypeoption_replace', 'tool_imageextractor'),
            ]);
            $mform->setDefault('jobtype', $jobtype);
            $mform->addHelpButton('jobtype', 'jobtype', 'tool_imageextractor');
        }

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

        // Criteria (shared: both types select files the same way).
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

        // Replace-only refinement of the criteria. Unlike the criteria fields
        // this stays available for match lists: replacing only the broken ones
        // of the nominated files is a legitimate combination.
        $mform->addElement(
            'advcheckbox',
            'missingonly',
            get_string('missingonly', 'tool_imageextractor'),
            get_string('missingonly_help', 'tool_imageextractor')
        );
        $mform->hideIf('missingonly', 'jobtype', 'eq', 'extract');

        // Match estimate (extract-only), kept inside the expanded criteria
        // section so the live figure is visible without opening a collapsed
        // fieldset. The no-submit button recomputes server-side without
        // saving; the inline region is updated live by the estimate AMD module
        // and falls back to the button when JavaScript is unavailable. The
        // estimate reflects only the criteria fields, so it is hidden for
        // match lists too - it could not describe the nominated files.
        $mform->addElement('submit', 'estimatematches', get_string('estimatematches', 'tool_imageextractor'));
        $mform->registerNoSubmitButton('estimatematches');
        $mform->hideIf('estimatematches', 'jobtype', 'eq', 'replace');
        $mform->hideIf('estimatematches', 'csvmode', 'eq', 'match');
        $mform->addElement(
            'static',
            'estimatelive',
            get_string('estimatelive', 'tool_imageextractor'),
            \html_writer::span('—', 'tool_imageextractor-estimate', ['data-region' => 'tool_imageextractor-estimate'])
        );
        $mform->addHelpButton('estimatelive', 'estimatelive', 'tool_imageextractor');
        $mform->hideIf('estimatelive', 'jobtype', 'eq', 'replace');
        $mform->hideIf('estimatelive', 'csvmode', 'eq', 'match');

        // Output (extract-only): how the downloaded archives are produced.
        $mform->addElement('header', 'outputheader', get_string('output', 'tool_imageextractor'));
        $mform->hideIf('outputheader', 'jobtype', 'eq', 'replace');

        $mform->addElement(
            'text',
            'namingrule',
            get_string('namingrule', 'tool_imageextractor'),
            ['size' => 60]
        );
        $mform->setType('namingrule', PARAM_TEXT);
        $mform->setDefault('namingrule', '{originalname}');
        $mform->addHelpButton('namingrule', 'namingrule', 'tool_imageextractor');
        $mform->hideIf('namingrule', 'jobtype', 'eq', 'replace');

        $mform->addElement(
            'advcheckbox',
            'dedupe',
            get_string('dedupe', 'tool_imageextractor'),
            get_string('dedupe_help', 'tool_imageextractor')
        );
        $mform->setDefault('dedupe', 1);
        $mform->hideIf('dedupe', 'jobtype', 'eq', 'replace');

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
        $mform->hideIf('volumemb', 'jobtype', 'eq', 'replace');

        // Replacement source (replace-only): what to upload over the matches.
        if ($replaceavailable) {
            $mform->addElement('header', 'replacementheader', get_string('replacement', 'tool_imageextractor'));
            $mform->hideIf('replacementheader', 'jobtype', 'eq', 'extract');

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
            $mform->hideIf('replacemode', 'jobtype', 'eq', 'extract');

            $mform->addElement(
                'filepicker',
                'replacementfile',
                get_string('replacementfile', 'tool_imageextractor'),
                null,
                ['accepted_types' => ['web_image'], 'maxfiles' => 1]
            );
            $mform->hideIf('replacementfile', 'jobtype', 'eq', 'extract');
            $mform->hideIf('replacementfile', 'replacemode', 'eq', 'zip');

            $mform->addElement(
                'filepicker',
                'replacementzip',
                get_string('replacementzip', 'tool_imageextractor'),
                null,
                ['accepted_types' => ['.zip'], 'maxfiles' => 1]
            );
            $mform->hideIf('replacementzip', 'jobtype', 'eq', 'extract');
            $mform->hideIf('replacementzip', 'replacemode', 'eq', 'single');

            $mform->addElement(
                'advcheckbox',
                'backup',
                get_string('backup', 'tool_imageextractor'),
                get_string('backup_help', 'tool_imageextractor')
            );
            $mform->setDefault('backup', 1);
            $mform->hideIf('backup', 'jobtype', 'eq', 'extract');
        }

        $this->add_action_buttons();
    }

    /**
     * Validate submitted data for whichever type was chosen.
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
        // CSV is preloaded into the draft area, which satisfies this.
        if (($data['csvmode'] ?? 'none') !== 'none' && !$this->get_new_filename('csvfile')) {
            $errors['csvfile'] = get_string('errorcsvrequired', 'tool_imageextractor');
        }

        $isreplace = ($data['jobtype'] ?? 'extract') === 'replace';

        if (!$isreplace) {
            if (isset($data['volumemb']) && (int) $data['volumemb'] < 1) {
                $errors['volumemb'] = get_string('errorvolumesize', 'tool_imageextractor');
            }
            return $errors;
        }

        // Per-row criteria CSVs remain extract-only: each row widens the
        // selection, which is too easy to get wrong for a destructive job.
        if (($data['csvmode'] ?? 'none') === 'criteria') {
            $errors['csvmode'] = get_string('errorcsvcriteriareplace', 'tool_imageextractor');
        }

        // A replacement source is required, unless editing an existing job that
        // already has a stored source FOR THE SELECTED MODE. Switching, say,
        // single -> zip without uploading a new archive must not pass, or the
        // job would run against the stale single image (or nothing).
        $mode = $data['replacemode'] ?? 'single';
        $storedmode = $this->_customdata['storedreplacemode'] ?? '';
        $hasstoredsource = !empty($this->_customdata['hasstoredsource']);
        $hasstored = !empty($data['id']) && $hasstoredsource && $mode === $storedmode;

        if ($mode === 'zip') {
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
