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
 * Extraction jobs overview.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use tool_imageextractor\manager;

admin_externalpage_setup('tool_imageextractor');

$context = context_system::instance();
require_capability('tool/imageextractor:manage', $context);

$PAGE->set_url(new moodle_url('/admin/tool/imageextractor/index.php'));
$PAGE->set_title(get_string('jobs', 'tool_imageextractor'));
$PAGE->set_heading(get_string('jobs', 'tool_imageextractor'));

$output = $PAGE->get_renderer('core');

echo $OUTPUT->header();

if (!manager::is_enabled()) {
    echo $OUTPUT->notification(get_string('disabledwarning', 'tool_imageextractor'), 'warning');
}

// A job is created criteria-only; the extract or replace action is chosen
// later from its results page, so there is a single "New job" entry point.
$buttons = $OUTPUT->single_button(
    new moodle_url('/admin/tool/imageextractor/edit.php'),
    get_string('newjob', 'tool_imageextractor'),
    'get'
);
echo html_writer::div($buttons, 'mb-3');

$jobs = manager::get_jobs();

if (!$jobs) {
    echo $OUTPUT->notification(get_string('nojobs', 'tool_imageextractor'), 'info');
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->head = [
    get_string('jobname', 'tool_imageextractor'),
    get_string('jobtype', 'tool_imageextractor'),
    get_string('status', 'tool_imageextractor'),
    get_string('progress', 'tool_imageextractor'),
    get_string('timecreated', 'tool_imageextractor'),
    get_string('actions', 'tool_imageextractor'),
];
$table->attributes['class'] = 'generaltable';

foreach ($jobs as $job) {
    $statuslabel = get_string('jobstatus_' . $job->status, 'tool_imageextractor');

    $isrunning = in_array($job->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true);
    if ($job->status === manager::STATUS_CLEARING) {
        // Results are being removed in the background; no meaningful progress.
        $progress = get_string('clearing', 'tool_imageextractor');
    } else if ($isrunning && (int) $job->totalmatched === 0) {
        // The analyse phase has no totals until it finishes.
        $progress = get_string('analysing', 'tool_imageextractor');
    } else {
        $progress = tool_imageextractor_progress_bar((int) $job->processedcount, (int) $job->totalmatched);
    }

    $viewurl = new moodle_url('/admin/tool/imageextractor/view.php', ['id' => $job->id]);
    $editurl = new moodle_url('/admin/tool/imageextractor/edit.php', ['id' => $job->id]);
    $actions = html_writer::link($viewurl, get_string('view'))
        . ' | ' . html_writer::link($editurl, get_string('edit'));

    $jobtypelabel = $job->jobtype !== ''
        ? get_string('jobtype_' . $job->jobtype, 'tool_imageextractor')
        : get_string('jobtype_unset', 'tool_imageextractor');
    $table->data[] = [
        html_writer::link($viewurl, format_string($job->name)),
        $jobtypelabel,
        $statuslabel,
        $progress,
        userdate($job->timecreated),
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
