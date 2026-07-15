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
 * Plugin version and metadata.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'tool_imageextractor';
$plugin->version   = 2026071501;
$plugin->requires  = 2025041400; // Moodle 5.0.
$plugin->supported = [500, 502];
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.16.0-beta (Build 2026071501)';
