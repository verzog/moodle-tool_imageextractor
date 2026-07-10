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
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026070907) {
        // Index (jobid, fileid) so the throttled matcher's per-batch idempotency
        // delete (removing any items beyond the cursor before re-recording a
        // page) stays cheap on a job with millions of items.
        $table = new xmldb_table('tool_imageextractor_item');
        $index = new xmldb_index('jobid-fileid', XMLDB_INDEX_NOTUNIQUE, ['jobid', 'fileid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026070907, 'tool', 'imageextractor');
    }

    return true;
}
