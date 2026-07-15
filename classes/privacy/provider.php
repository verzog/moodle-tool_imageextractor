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
 * Privacy provider for tool_imageextractor.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The only user-correlated data this plugin stores itself is the admin who
 * authored each job (tool_imageextractor_job.usermodified). The extracted
 * archives and manifests can of course contain other people's images and
 * metadata, but those are system aggregates with no per-user attribution
 * path - we do not declare them for per-user export, and the context-wide
 * purge deletes the whole area.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe what user data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'tool_imageextractor_job',
            [
                'name'         => 'privacy:metadata:job:name',
                'usermodified' => 'privacy:metadata:job:usermodified',
                'timemodified' => 'privacy:metadata:job:timemodified',
            ],
            'privacy:metadata:job'
        );
        // Each matched file records the id of the user who uploaded it, so a
        // user can be referenced by a job they did not create.
        $collection->add_database_table(
            'tool_imageextractor_item',
            [
                'uploaderid'      => 'privacy:metadata:item:uploaderid',
                'author'          => 'privacy:metadata:item:author',
                'filename'        => 'privacy:metadata:item:filename',
                'contenthash'     => 'privacy:metadata:item:contenthash',
                'filetimecreated' => 'privacy:metadata:item:filetimecreated',
            ],
            'privacy:metadata:item'
        );
        return $collection;
    }

    /**
     * Jobs only ever live at the system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $hasdata = $DB->record_exists('tool_imageextractor_job', ['usermodified' => $userid])
            || $DB->record_exists('tool_imageextractor_item', ['uploaderid' => $userid]);
        if ($hasdata) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Reverse lookup: which users authored jobs at this context?
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!($userlist->get_context() instanceof \context_system)) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT usermodified AS userid FROM {tool_imageextractor_job} WHERE usermodified <> 0',
            []
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT uploaderid AS userid FROM {tool_imageextractor_item} WHERE uploaderid <> 0',
            []
        );
    }

    /**
     * Export the jobs authored by each user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }
            $jobs = $DB->get_records(
                'tool_imageextractor_job',
                ['usermodified' => $user->id],
                'timemodified DESC'
            );
            if ($jobs) {
                $export = array_map(static function ($job) {
                    return (object) [
                        'name'         => format_string($job->name),
                        'status'       => $job->status,
                        'timemodified' => transform::datetime($job->timemodified),
                    ];
                }, $jobs);
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'tool_imageextractor')],
                    (object) ['jobs' => array_values($export)]
                );
            }

            // Files of this user that were captured by any job (theirs or not).
            $items = $DB->get_records(
                'tool_imageextractor_item',
                ['uploaderid' => $user->id],
                'id ASC'
            );
            if ($items) {
                $itemexport = array_map(static function ($item) {
                    return (object) [
                        'filename'        => $item->filename,
                        'contenthash'     => $item->contenthash,
                        'filetimecreated' => $item->filetimecreated
                            ? transform::datetime($item->filetimecreated) : null,
                    ];
                }, $items);
                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'tool_imageextractor'),
                        get_string('privacy:path:items', 'tool_imageextractor'),
                    ],
                    (object) ['items' => array_values($itemexport)]
                );
            }
        }
    }

    /**
     * Purge all attribution at the given context.
     *
     * Jobs are admin-created configuration, not the subject's personal data,
     * so we anonymise the authorship rather than deleting the jobs.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!($context instanceof \context_system)) {
            return;
        }
        $DB->set_field('tool_imageextractor_job', 'usermodified', 0, []);
        $DB->set_field('tool_imageextractor_item', 'uploaderid', 0, []);
    }

    /**
     * Anonymise one user's authorship across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }
            $DB->set_field('tool_imageextractor_job', 'usermodified', 0, ['usermodified' => $user->id]);
            $DB->set_field('tool_imageextractor_item', 'uploaderid', 0, ['uploaderid' => $user->id]);
        }
    }

    /**
     * Anonymise a set of users' authorship at one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if (!($userlist->get_context() instanceof \context_system)) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tie');
        $DB->execute(
            "UPDATE {tool_imageextractor_job} SET usermodified = 0 WHERE usermodified $insql",
            $params
        );
        $DB->execute(
            "UPDATE {tool_imageextractor_item} SET uploaderid = 0 WHERE uploaderid $insql",
            $params
        );
    }
}
