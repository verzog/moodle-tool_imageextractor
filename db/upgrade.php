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
 * Database upgrade steps.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

/**
 * Upgrade the plugin database.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_tool_imageextractor_upgrade($oldversion) {
    // No upgrade steps yet - the install schema is current. New steps go
    // here, each guarded by an $oldversion check and an upgrade savepoint.
    unset($oldversion);
    return true;
}
