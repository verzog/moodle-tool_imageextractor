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
 * External (web service) function definitions.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'tool_imageextractor_estimate_matches' => [
        'classname'     => 'tool_imageextractor\\external\\estimate_matches',
        'description'   => 'Estimate how many files (and how many bytes) match the given criteria.',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'tool/imageextractor:manage',
    ],
];
