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

    if ($oldversion < 2026071400) {
        // Live progress reporting for the background analyse phase: which stage
        // is running (clearing previous results or matching files), how far it
        // has got, and the estimated total, so the UI can render a progress bar
        // while a large analysis is still scanning.
        $table = new xmldb_table('tool_imageextractor_job');

        $field = new xmldb_field('progressstage', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'volumecount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('progressdone', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'progressstage');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('progresstotal', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'progressdone');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071400, 'tool', 'imageextractor');
    }

    if ($oldversion < 2026071500) {
        // Replace-job options for replacement optimization (longest-edge cap +
        // re-encode quality) and the metadata-only replace mode (new author /
        // license without touching content).
        $table = new xmldb_table('tool_imageextractor_job');
        $fields = [
            new xmldb_field('optimizemaxpx', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'missingonly'),
            new xmldb_field('optimizequality', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '85', 'optimizemaxpx'),
            new xmldb_field('metaauthor', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'optimizequality'),
            new xmldb_field('metalicense', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'metaauthor'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Per-item image metadata for the export manifest and sidecars: the
        // file's recorded author and license (captured at match time) and its
        // pixel dimensions (resolved when packed).
        $table = new xmldb_table('tool_imageextractor_item');
        $fields = [
            new xmldb_field('author', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'uploaderid'),
            new xmldb_field('license', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'author'),
            new xmldb_field('imagewidth', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'license'),
            new xmldb_field('imageheight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'imagewidth'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026071500, 'tool', 'imageextractor');
    }

    if ($oldversion < 2026071501) {
        // Per-item alt text (the image's description, read from the HTML that
        // embeds it) for the export manifest and the alt-text audit.
        $table = new xmldb_table('tool_imageextractor_item');
        $field = new xmldb_field('alttext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'imageheight');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071501, 'tool', 'imageextractor');
    }

    if ($oldversion < 2026071502) {
        // The "missing alt text" accessibility refinement (select only images
        // embedded via an img tag with an empty/missing alt).
        $table = new xmldb_table('tool_imageextractor_job');
        $field = new xmldb_field('altmissing', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'missingonly');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The per-field HTML backup that makes an alt-text replace reversible.
        $table = new xmldb_table('tool_imageextractor_htmlbackup');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('tablename', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('columnname', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rowid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('oldcontent', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('jobid', XMLDB_KEY_FOREIGN, ['jobid'], 'tool_imageextractor_job', ['id']);
            $table->add_index('jobid-field', XMLDB_INDEX_UNIQUE, ['jobid', 'tablename', 'columnname', 'rowid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071502, 'tool', 'imageextractor');
    }

    return true;
}
