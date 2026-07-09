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
 * Web service that estimates how many files match a set of criteria.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use tool_imageextractor\manager;
use tool_imageextractor\matcher;

/**
 * Returns an approximate match count and total size for the given criteria,
 * so the edit form can show a live estimate as criteria are changed.
 */
class estimate_matches extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'imageonly'       => new external_value(PARAM_BOOL, 'Restrict to images', VALUE_DEFAULT, true),
            'dedupe'          => new external_value(PARAM_BOOL, 'Collapse duplicate content to one file', VALUE_DEFAULT, true),
            'component'       => new external_value(PARAM_COMPONENT, 'Component', VALUE_DEFAULT, ''),
            'filearea'        => new external_value(PARAM_AREA, 'File area', VALUE_DEFAULT, ''),
            'filenamepattern' => new external_value(PARAM_TEXT, 'Filename pattern (* wildcard)', VALUE_DEFAULT, ''),
            'mimetypes'       => new external_value(PARAM_TEXT, 'Comma-separated MIME prefixes', VALUE_DEFAULT, ''),
            'minsizekb'       => new external_value(PARAM_INT, 'Minimum size in KB', VALUE_DEFAULT, 0),
            'maxsizekb'       => new external_value(PARAM_INT, 'Maximum size in KB', VALUE_DEFAULT, 0),
            'datefrom'        => new external_value(PARAM_INT, 'Created on or after (epoch seconds)', VALUE_DEFAULT, 0),
            'dateto'          => new external_value(PARAM_INT, 'Created on or before (epoch seconds)', VALUE_DEFAULT, 0),
            'courseids'       => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Course scope',
                VALUE_DEFAULT,
                []
            ),
            'categoryids'     => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course category id'),
                'Category scope',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Estimate the result set for the given criteria.
     *
     * @param bool $imageonly
     * @param bool $dedupe
     * @param string $component
     * @param string $filearea
     * @param string $filenamepattern
     * @param string $mimetypes
     * @param int $minsizekb
     * @param int $maxsizekb
     * @param int $datefrom
     * @param int $dateto
     * @param int[] $courseids
     * @param int[] $categoryids
     * @return array Match count, total bytes and a human-readable size.
     */
    public static function execute(
        bool $imageonly,
        bool $dedupe,
        string $component,
        string $filearea,
        string $filenamepattern,
        string $mimetypes,
        int $minsizekb,
        int $maxsizekb,
        int $datefrom,
        int $dateto,
        array $courseids,
        array $categoryids
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'imageonly'       => $imageonly,
            'dedupe'          => $dedupe,
            'component'       => $component,
            'filearea'        => $filearea,
            'filenamepattern' => $filenamepattern,
            'mimetypes'       => $mimetypes,
            'minsizekb'       => $minsizekb,
            'maxsizekb'       => $maxsizekb,
            'datefrom'        => $datefrom,
            'dateto'          => $dateto,
            'courseids'       => $courseids,
            'categoryids'     => $categoryids,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/imageextractor:manage', $context);

        // Reuse the exact same form-to-criteria mapping as saving a job, so the
        // live estimate matches what the job would actually select.
        $criteria = manager::criteria_from_data((object) [
            'imageonly'       => $params['imageonly'],
            'component'       => $params['component'],
            'filearea'        => $params['filearea'],
            'filenamepattern' => $params['filenamepattern'],
            'mimetypes'       => $params['mimetypes'],
            'minsizekb'       => $params['minsizekb'],
            'maxsizekb'       => $params['maxsizekb'],
            'datefrom'        => $params['datefrom'],
            'dateto'          => $params['dateto'],
            'courseids'       => $params['courseids'],
            'categoryids'     => $params['categoryids'],
        ]);

        $estimate = (new matcher($criteria, $params['dedupe']))->estimate();

        return [
            'count'         => $estimate['count'],
            'bytes'         => $estimate['bytes'],
            'formattedsize' => display_size($estimate['bytes']),
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count'         => new external_value(PARAM_INT, 'Number of matching files'),
            'bytes'         => new external_value(PARAM_INT, 'Total size in bytes'),
            'formattedsize' => new external_value(PARAM_RAW, 'Human-readable total size'),
        ]);
    }
}
