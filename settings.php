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
 * Admin settings - adds the management and settings pages to the admin tree.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Group the jobs overview and the settings page together under a single
    // Image extractor category in the admin tree.
    $ADMIN->add('tools', new admin_category(
        'tool_imageextractor_category',
        get_string('pluginname', 'tool_imageextractor')
    ));

    $ADMIN->add('tool_imageextractor_category', new admin_externalpage(
        'tool_imageextractor',
        get_string('jobs', 'tool_imageextractor'),
        new moodle_url('/admin/tool/imageextractor/index.php'),
        'tool/imageextractor:manage'
    ));

    $settings = new admin_settingpage(
        'tool_imageextractor_settings',
        get_string('settings', 'tool_imageextractor')
    );

    // Master kill-switch. When off, no new job will run and queued tasks
    // bail out without doing work, so a misconfigured job can be stopped
    // site-wide without a code change.
    $settings->add(new admin_setting_configcheckbox(
        'tool_imageextractor/enabled',
        get_string('setting_enabled', 'tool_imageextractor'),
        get_string('setting_enabled_desc', 'tool_imageextractor'),
        1
    ));

    // Default ZIP volume size offered on the job form, in megabytes. A
    // browser cannot reliably download a single multi-gigabyte archive, so
    // large jobs are split into capped volumes.
    $settings->add(new admin_setting_configtext(
        'tool_imageextractor/default_volume_mb',
        get_string('setting_default_volume_mb', 'tool_imageextractor'),
        get_string('setting_default_volume_mb_desc', 'tool_imageextractor'),
        2048,
        PARAM_INT
    ));

    // Concurrency cap for the process_job adhoc task. Building ZIP volumes
    // is IO-heavy; the default of 1 keeps a large job from monopolising the
    // cron worker pool.
    $settings->add(new admin_setting_configtext(
        'tool_imageextractor/process_concurrency',
        get_string('setting_process_concurrency', 'tool_imageextractor'),
        get_string('setting_process_concurrency_desc', 'tool_imageextractor'),
        1,
        PARAM_INT
    ));

    // Replace/restore is destructive - it rewrites live site files - so it is
    // off by default. A site admin must opt in here before a replace job can
    // be created or run, and unticking this stops in-flight replace tasks.
    $settings->add(new admin_setting_configcheckbox(
        'tool_imageextractor/allow_replace',
        get_string('setting_allow_replace', 'tool_imageextractor'),
        get_string('setting_allow_replace_desc', 'tool_imageextractor'),
        0
    ));

    // Concurrency cap for the replace adhoc task. Replacing files writes to
    // the file storage, so the default of 1 avoids contention.
    $settings->add(new admin_setting_configtext(
        'tool_imageextractor/replace_concurrency',
        get_string('setting_replace_concurrency', 'tool_imageextractor'),
        get_string('setting_replace_concurrency_desc', 'tool_imageextractor'),
        1,
        PARAM_INT
    ));

    // How many days completed jobs and their generated archives are kept
    // before the cleanup scheduled task removes them. Zero disables
    // automatic cleanup.
    $settings->add(new admin_setting_configtext(
        'tool_imageextractor/retention_days',
        get_string('setting_retention_days', 'tool_imageextractor'),
        get_string('setting_retention_days_desc', 'tool_imageextractor'),
        30,
        PARAM_INT
    ));

    $ADMIN->add('tool_imageextractor_category', $settings);
}
