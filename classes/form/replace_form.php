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
require_once($CFG->libdir . '/licenselib.php');

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
            'single'   => get_string('replacemode_single', 'tool_imageextractor'),
            'zip'      => get_string('replacemode_zip', 'tool_imageextractor'),
            'metadata' => get_string('replacemode_metadata', 'tool_imageextractor'),
            'alttext'  => get_string('replacemode_alttext', 'tool_imageextractor'),
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
        $mform->hideIf('replacementfile', 'replacemode', 'eq', 'metadata');
        $mform->hideIf('replacementfile', 'replacemode', 'eq', 'alttext');

        // A filemanager rather than a filepicker: a replacement set larger
        // than the site's upload limit can be supplied as several smaller ZIP
        // chunks (e.g. re-uploading the extract's volumes one by one), which
        // all unpack into the same matching pool.
        $mform->addElement(
            'filemanager',
            'replacementzip',
            get_string('replacementzip', 'tool_imageextractor'),
            null,
            ['accepted_types' => ['.zip'], 'maxfiles' => 50, 'subdirs' => 0]
        );
        $mform->addHelpButton('replacementzip', 'replacementzip', 'tool_imageextractor');
        $mform->hideIf('replacementzip', 'replacemode', 'eq', 'single');
        $mform->hideIf('replacementzip', 'replacemode', 'eq', 'metadata');
        $mform->hideIf('replacementzip', 'replacemode', 'eq', 'alttext');

        // Alt-text mode: a CSV mapping each file name to the description to
        // write. The exported manifest (filename + alttext columns) is the
        // natural starting point - edit its alttext column and upload it here.
        $mform->addElement(
            'filepicker',
            'altcsvfile',
            get_string('altcsvfile', 'tool_imageextractor'),
            null,
            ['accepted_types' => ['.csv', '.txt'], 'maxfiles' => 1]
        );
        $mform->addHelpButton('altcsvfile', 'altcsvfile', 'tool_imageextractor');
        $mform->hideIf('altcsvfile', 'replacemode', 'neq', 'alttext');

        // Metadata-only mode: the new author and/or license to stamp on every
        // matched file. Content is never touched.
        $mform->addElement(
            'text',
            'metaauthor',
            get_string('metaauthor', 'tool_imageextractor'),
            ['size' => 40]
        );
        $mform->setType('metaauthor', PARAM_TEXT);
        $mform->addHelpButton('metaauthor', 'metaauthor', 'tool_imageextractor');
        $mform->hideIf('metaauthor', 'replacemode', 'neq', 'metadata');

        $licenses = ['' => get_string('metalicense_keep', 'tool_imageextractor')];
        foreach (\license_manager::get_active_licenses() as $license) {
            $licenses[$license->shortname] = $license->fullname;
        }
        $mform->addElement('select', 'metalicense', get_string('metalicense', 'tool_imageextractor'), $licenses);
        $mform->setDefault('metalicense', '');
        $mform->addHelpButton('metalicense', 'metalicense', 'tool_imageextractor');
        $mform->hideIf('metalicense', 'replacemode', 'neq', 'metadata');

        // Optimization of the uploaded replacements before they are applied:
        // cap the longest edge and re-encode at the given quality. Runs in the
        // background, once per uploaded file.
        $mform->addElement(
            'advcheckbox',
            'optimize',
            get_string('optimize', 'tool_imageextractor'),
            get_string('optimize_label', 'tool_imageextractor')
        );
        $mform->addHelpButton('optimize', 'optimize', 'tool_imageextractor');
        $mform->hideIf('optimize', 'replacemode', 'eq', 'metadata');
        $mform->hideIf('optimize', 'replacemode', 'eq', 'alttext');

        $mform->addElement(
            'text',
            'optimizemaxpx',
            get_string('optimizemaxpx', 'tool_imageextractor'),
            ['size' => 8]
        );
        $mform->setType('optimizemaxpx', PARAM_INT);
        $mform->setDefault('optimizemaxpx', 1920);
        $mform->hideIf('optimizemaxpx', 'optimize', 'notchecked');
        $mform->hideIf('optimizemaxpx', 'replacemode', 'eq', 'metadata');
        $mform->hideIf('optimizemaxpx', 'replacemode', 'eq', 'alttext');

        $mform->addElement(
            'text',
            'optimizequality',
            get_string('optimizequality', 'tool_imageextractor'),
            ['size' => 8]
        );
        $mform->setType('optimizequality', PARAM_INT);
        $mform->setDefault('optimizequality', 85);
        $mform->hideIf('optimizequality', 'optimize', 'notchecked');
        $mform->hideIf('optimizequality', 'replacemode', 'eq', 'metadata');
        $mform->hideIf('optimizequality', 'replacemode', 'eq', 'alttext');

        $mform->addElement(
            'advcheckbox',
            'backup',
            get_string('backup', 'tool_imageextractor'),
            get_string('backup_help', 'tool_imageextractor')
        );
        $mform->setDefault('backup', 1);
        $mform->hideIf('backup', 'replacemode', 'eq', 'metadata');
        $mform->hideIf('backup', 'replacemode', 'eq', 'alttext');

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

        // Metadata-only mode needs no upload, but changing nothing is a no-op:
        // require a new author and/or a license.
        if ($mode === 'metadata') {
            if (trim((string) ($data['metaauthor'] ?? '')) === '' && trim((string) ($data['metalicense'] ?? '')) === '') {
                $errors['metaauthor'] = get_string('errornometadata', 'tool_imageextractor');
            }
            return $errors;
        }

        // Alt-text mode requires the description CSV (checked via the draft
        // area directly, never get_new_filename() - see the note below).
        if ($mode === 'alttext') {
            if (!manager::draft_has_file((int) ($data['altcsvfile'] ?? 0))) {
                $errors['altcsvfile'] = get_string('erroraltcsvrequired', 'tool_imageextractor');
            }
            return $errors;
        }

        // Optimization bounds - only meaningful when switched on.
        if (!empty($data['optimize'])) {
            $maxpx = (int) ($data['optimizemaxpx'] ?? 0);
            if ($maxpx < 16 || $maxpx > 20000) {
                $errors['optimizemaxpx'] = get_string('erroroptimizemaxpx', 'tool_imageextractor');
            }
            $quality = (int) ($data['optimizequality'] ?? 0);
            if ($quality < 1 || $quality > 100) {
                $errors['optimizequality'] = get_string('erroroptimizequality', 'tool_imageextractor');
            }
        }

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
