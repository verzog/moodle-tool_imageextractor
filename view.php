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
 * View one extraction job: estimate, run, monitor and download results.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_imageextractor\manager;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('tool_imageextractor');

$context = context_system::instance();
require_capability('tool/imageextractor:manage', $context);

$job = manager::get_job($id);

$viewurl = new moodle_url('/admin/tool/imageextractor/view.php', ['id' => $id]);
$indexurl = new moodle_url('/admin/tool/imageextractor/index.php');
$PAGE->set_url($viewurl);
$PAGE->set_title(format_string($job->name));
$PAGE->set_heading(format_string($job->name));

$running = in_array($job->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true);
$isreplace = ($job->jobtype === 'replace');

// Handle actions.
if ($action !== '' && confirm_sesskey()) {
    if ($action === 'run' && !$running) {
        if (!manager::is_enabled() || ($isreplace && !manager::is_replace_allowed())) {
            \core\notification::error(get_string('disabledwarning', 'tool_imageextractor'));
            redirect($viewurl);
        }
        if ($confirm) {
            manager::queue_job($id);
            \core\notification::success(get_string('jobqueued', 'tool_imageextractor'));
            redirect($viewurl);
        }
        echo $OUTPUT->header();
        $estimate = manager::estimate($job);
        $message = get_string($isreplace ? 'confirmreplace' : 'confirmrun', 'tool_imageextractor', (object) [
            'count' => $estimate['count'],
            'size'  => display_size($estimate['bytes']),
        ]);
        echo $OUTPUT->confirm(
            $message,
            new moodle_url($viewurl, ['action' => 'run', 'confirm' => 1, 'sesskey' => sesskey()]),
            $viewurl
        );
        echo $OUTPUT->footer();
        die();
    }

    if ($action === 'restore' && $isreplace && !$running) {
        if (!manager::is_enabled() || !manager::is_replace_allowed()) {
            \core\notification::error(get_string('disabledwarning', 'tool_imageextractor'));
            redirect($viewurl);
        }
        if ($confirm) {
            manager::queue_restore($id);
            \core\notification::success(get_string('restorequeued', 'tool_imageextractor'));
            redirect($viewurl);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmrestore', 'tool_imageextractor'),
            new moodle_url($viewurl, ['action' => 'restore', 'confirm' => 1, 'sesskey' => sesskey()]),
            $viewurl
        );
        echo $OUTPUT->footer();
        die();
    }

    if ($action === 'delete') {
        if ($confirm) {
            manager::delete_job($id);
            \core\notification::success(get_string('jobdeleted', 'tool_imageextractor'));
            redirect($indexurl);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmdelete', 'tool_imageextractor', format_string($job->name)),
            new moodle_url($viewurl, ['action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]),
            $viewurl
        );
        echo $OUTPUT->footer();
        die();
    }

    if ($action === 'clear' && !$running) {
        manager::clear_results($id);
        $DB->set_field('tool_imageextractor_job', 'status', manager::STATUS_DRAFT, ['id' => $id]);
        \core\notification::success(get_string('resultscleared', 'tool_imageextractor'));
        redirect($viewurl);
    }
}

echo $OUTPUT->header();

if (!manager::is_enabled()) {
    echo $OUTPUT->notification(get_string('disabledwarning', 'tool_imageextractor'), 'warning');
}

if (!empty($job->error)) {
    echo $OUTPUT->notification(get_string('joberror', 'tool_imageextractor', s($job->error)), 'error');
}

// Summary table.
$summary = new html_table();
$summary->attributes['class'] = 'generaltable';
$summary->data[] = [get_string('jobtype', 'tool_imageextractor'),
    get_string('jobtype_' . $job->jobtype, 'tool_imageextractor')];
$summary->data[] = [get_string('status', 'tool_imageextractor'),
    get_string('jobstatus_' . $job->status, 'tool_imageextractor')];
if ($job->description !== '') {
    $summary->data[] = [get_string('jobdescription', 'tool_imageextractor'),
        format_text($job->description, FORMAT_PLAIN)];
}
if ($isreplace) {
    $summary->data[] = [get_string('replacemode', 'tool_imageextractor'),
        get_string('replacemode_' . $job->replacemode, 'tool_imageextractor')];
    $summary->data[] = [get_string('backup', 'tool_imageextractor'),
        $job->backup ? get_string('yes') : get_string('no')];
    $summary->data[] = [get_string('missingonly', 'tool_imageextractor'),
        $job->missingonly ? get_string('yes') : get_string('no')];
} else {
    $summary->data[] = [get_string('namingrule', 'tool_imageextractor'), s($job->namingrule)];
    $summary->data[] = [get_string('volumemb', 'tool_imageextractor'),
        display_size((int) $job->volumesize)];
}
if ((int) $job->totalmatched > 0) {
    $summary->data[] = [get_string('totalmatched', 'tool_imageextractor'),
        $job->totalmatched . ' (' . display_size((int) $job->totalbytes) . ')'];
    $summary->data[] = [get_string('progress', 'tool_imageextractor'),
        $job->processedcount . ' / ' . $job->totalmatched
        . ' (' . display_size((int) $job->processedbytes) . ')'];
}
if ((int) $job->failedcount > 0) {
    $summary->data[] = [get_string('failedcount', 'tool_imageextractor'), $job->failedcount];
}
echo html_writer::table($summary);

if ($running) {
    echo $OUTPUT->notification(get_string('runninghint', 'tool_imageextractor'), 'info');
}

// Download links (extract jobs only).
$volumes = $isreplace ? [] : manager::get_volumes($id);
if ($volumes) {
    echo $OUTPUT->heading(get_string('downloads', 'tool_imageextractor'), 3);
    $list = [];
    if (manager::has_manifest($id)) {
        $manifesturl = moodle_url::make_pluginfile_url(
            $context->id,
            manager::COMPONENT,
            'manifest',
            $id,
            '/',
            'manifest.csv',
            true
        );
        $list[] = html_writer::link($manifesturl, get_string('manifest', 'tool_imageextractor'));
    }
    foreach ($volumes as $volume) {
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            manager::COMPONENT,
            'volumes',
            $volume->sequence,
            '/',
            $volume->filename,
            true
        );
        $list[] = html_writer::link($url, $volume->filename)
            . ' (' . $volume->filecount . ' ' . get_string('files', 'tool_imageextractor')
            . ', ' . display_size((int) $volume->filesize) . ')';
    }
    echo html_writer::alist($list);
}

// Action buttons - hidden while the job is running so it cannot be double-fired.
echo html_writer::start_div('mt-3');
if (!$running) {
    echo $OUTPUT->single_button(
        new moodle_url($viewurl, ['action' => 'run', 'sesskey' => sesskey()]),
        get_string('runjob', 'tool_imageextractor'),
        'get'
    );
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/imageextractor/edit.php', ['id' => $id]),
        get_string('edit'),
        'get'
    );
    if ($isreplace && $job->status === manager::STATUS_COMPLETED && manager::has_restorable($id)) {
        echo $OUTPUT->single_button(
            new moodle_url($viewurl, ['action' => 'restore', 'sesskey' => sesskey()]),
            get_string('restorejob', 'tool_imageextractor'),
            'get'
        );
    }
    if ($volumes || (int) $job->totalmatched > 0) {
        echo $OUTPUT->single_button(
            new moodle_url($viewurl, ['action' => 'clear', 'sesskey' => sesskey()]),
            get_string('clearresults', 'tool_imageextractor'),
            'get'
        );
    }
    echo $OUTPUT->single_button(
        new moodle_url($viewurl, ['action' => 'delete', 'sesskey' => sesskey()]),
        get_string('delete'),
        'get'
    );
}
echo html_writer::end_div();

echo html_writer::div(html_writer::link($indexurl, get_string('backtojobs', 'tool_imageextractor')), 'mt-3');

echo $OUTPUT->footer();
