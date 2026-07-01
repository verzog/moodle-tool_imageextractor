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
 * Shared file-matching form elements used by both the extract and replace forms.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\form;

/**
 * Adds the common "which files" criteria elements to a form, and shares their
 * validation, so the extract and replace forms select files the same way.
 */
class criteria_fields {
    /**
     * Add the shared criteria elements to a form.
     *
     * @param \MoodleQuickForm $mform
     * @return void
     */
    public static function add(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'advcheckbox',
            'imageonly',
            get_string('imageonly', 'tool_imageextractor'),
            get_string('imageonly_help', 'tool_imageextractor')
        );
        $mform->setDefault('imageonly', 1);

        // Course scope. The 'course' element is an autocomplete that yields an
        // array of course ids; the matcher restricts to files whose context is
        // one of these courses or nested beneath it (activities, blocks).
        $mform->addElement(
            'course',
            'courseids',
            get_string('courses', 'tool_imageextractor'),
            ['multiple' => true, 'includefrontpage' => false]
        );
        $mform->addHelpButton('courseids', 'courses', 'tool_imageextractor');

        // Category scope. Selecting a category includes every course (and
        // subcategory) beneath it; the matcher resolves that by context path.
        $mform->addElement(
            'autocomplete',
            'categoryids',
            get_string('categories', 'tool_imageextractor'),
            \core_course_category::make_categories_list(),
            ['multiple' => true]
        );
        $mform->addHelpButton('categoryids', 'categories', 'tool_imageextractor');

        $mform->addElement(
            'text',
            'mimetypes',
            get_string('mimetypes', 'tool_imageextractor'),
            ['size' => 50]
        );
        $mform->setType('mimetypes', PARAM_TEXT);
        $mform->addHelpButton('mimetypes', 'mimetypes', 'tool_imageextractor');

        $mform->addElement(
            'text',
            'component',
            get_string('component', 'tool_imageextractor'),
            ['size' => 40, 'placeholder' => 'mod_forum']
        );
        $mform->setType('component', PARAM_COMPONENT);

        $mform->addElement(
            'text',
            'filearea',
            get_string('filearea', 'tool_imageextractor'),
            ['size' => 40, 'placeholder' => 'attachment']
        );
        $mform->setType('filearea', PARAM_AREA);

        $mform->addElement(
            'text',
            'filenamepattern',
            get_string('filenamepattern', 'tool_imageextractor'),
            ['size' => 40, 'placeholder' => '*.jpg']
        );
        $mform->setType('filenamepattern', PARAM_TEXT);
        $mform->addHelpButton('filenamepattern', 'filenamepattern', 'tool_imageextractor');

        $mform->addElement(
            'text',
            'minsizekb',
            get_string('minsizekb', 'tool_imageextractor'),
            ['size' => 10]
        );
        $mform->setType('minsizekb', PARAM_INT);

        $mform->addElement(
            'text',
            'maxsizekb',
            get_string('maxsizekb', 'tool_imageextractor'),
            ['size' => 10]
        );
        $mform->setType('maxsizekb', PARAM_INT);

        $mform->addElement(
            'date_selector',
            'datefrom',
            get_string('datefrom', 'tool_imageextractor'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_selector',
            'dateto',
            get_string('dateto', 'tool_imageextractor'),
            ['optional' => true]
        );
    }

    /**
     * Shared validation for the criteria elements.
     *
     * @param array $data
     * @return array Errors keyed by element name.
     */
    public static function validate(array $data): array {
        $errors = [];

        if (
            !empty($data['minsizekb']) && !empty($data['maxsizekb'])
                && (int) $data['minsizekb'] > (int) $data['maxsizekb']
        ) {
            $errors['maxsizekb'] = get_string('errorsizerange', 'tool_imageextractor');
        }

        if (
            !empty($data['datefrom']) && !empty($data['dateto'])
                && (int) $data['datefrom'] > (int) $data['dateto']
        ) {
            $errors['dateto'] = get_string('errordaterange', 'tool_imageextractor');
        }

        return $errors;
    }

    /**
     * Map a job's stored criteria back to form-field defaults.
     *
     * @param array $criteria
     * @return array Field name => default value.
     */
    public static function defaults_from_criteria(array $criteria): array {
        return [
            'imageonly'       => !empty($criteria['imageonly']) ? 1 : 0,
            'courseids'       => !empty($criteria['courseids']) && is_array($criteria['courseids'])
                ? array_values(array_map('intval', $criteria['courseids'])) : [],
            'categoryids'     => !empty($criteria['categoryids']) && is_array($criteria['categoryids'])
                ? array_values(array_map('intval', $criteria['categoryids'])) : [],
            'mimetypes'       => !empty($criteria['mimetypes']) ? implode(', ', $criteria['mimetypes']) : '',
            'component'       => $criteria['component'] ?? '',
            'filearea'        => $criteria['filearea'] ?? '',
            'filenamepattern' => $criteria['filenamepattern'] ?? '',
            'minsizekb'       => !empty($criteria['minsize']) ? (int) ($criteria['minsize'] / 1024) : '',
            'maxsizekb'       => !empty($criteria['maxsize']) ? (int) ($criteria['maxsize'] / 1024) : '',
            'datefrom'        => $criteria['datefrom'] ?? 0,
            'dateto'          => $criteria['dateto'] ?? 0,
        ];
    }
}
