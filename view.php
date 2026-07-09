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
require_once(__DIR__ . '/lib.php');

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

$running = in_array(
    $job->status,
    [manager::STATUS_QUEUED, manager::STATUS_PROCESSING, manager::STATUS_CLEARING],
    true
);
$isreplace = ($job->jobtype === 'replace');

// Handle actions.
if ($action !== '' && confirm_sesskey()) {
    if ($action === 'run' && !$running) {
        if (!manager::is_enabled() || ($isreplace && !manager::is_replace_allowed())) {
            \core\notification::error(get_string('disabledwarning', 'tool_imageextractor'));
            redirect($viewurl);
        }
        // Replacing live files is destructive, so restrict it to full site
        // administrators - the manage capability alone is not enough.
        if ($isreplace && !is_siteadmin()) {
            \core\notification::error(get_string('replaceadminonly', 'tool_imageextractor'));
            redirect($viewurl);
        }
        if (!$isreplace) {
            if ($confirm) {
                manager::queue_job($id);
                \core\notification::success(get_string('jobqueued', 'tool_imageextractor'));
                redirect($viewurl);
            }
            // No synchronous estimate here: counting matches can take minutes
            // on a large site and used to time out at the gateway. The edit
            // form offers an estimate; the run itself reports exact totals.
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string('confirmrunbackground', 'tool_imageextractor', format_string($job->name)),
                new moodle_url($viewurl, ['action' => 'run', 'confirm' => 1, 'sesskey' => sesskey()]),
                $viewurl
            );
            echo $OUTPUT->footer();
            die();
        }

        // Replace jobs run in two phases. Run first queues a background
        // analyse pass (nothing is changed); once that finishes the job page
        // shows an exact preview, and confirming from there - status
        // "review" - queues the destructive apply phase. The web flow can
        // therefore never reach apply without a completed analysis.
        if ($job->status === manager::STATUS_REVIEW) {
            if ($confirm) {
                manager::queue_job($id);
                \core\notification::success(get_string('jobqueued', 'tool_imageextractor'));
            }
            redirect($viewurl);
        }
        if ($confirm) {
            manager::queue_analyse($id);
            \core\notification::success(get_string('analysequeued', 'tool_imageextractor'));
            redirect($viewurl);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmanalyse', 'tool_imageextractor'),
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
        if (!is_siteadmin()) {
            \core\notification::error(get_string('replaceadminonly', 'tool_imageextractor'));
            redirect($viewurl);
        }
        if ($confirm) {
            manager::queue_restore($id);
            \core\notification::success(get_string('restorequeued', 'tool_imageextractor'));
            redirect($viewurl);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('restorewarning', 'tool_imageextractor'), 'warning');
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
        // A large result set is removed in the background so this cannot time
        // out; a small one is cleared inline. reset_results picks per job.
        $deferred = manager::reset_results($id);
        \core\notification::success(get_string(
            $deferred ? 'clearqueued' : 'resultscleared',
            'tool_imageextractor'
        ));
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
        tool_imageextractor_progress_bar((int) $job->processedcount, (int) $job->totalmatched)
        . html_writer::div(display_size((int) $job->processedbytes), 'small')];
}
if ((int) $job->failedcount > 0) {
    $summary->data[] = [get_string('failedcount', 'tool_imageextractor'), $job->failedcount];
}
echo html_writer::table($summary);

if ($running) {
    // Clearing has its own hint; otherwise a replace job with no matched totals
    // yet is still analysing, and anything else is the generic processing hint.
    if ($job->status === manager::STATUS_CLEARING) {
        $hint = 'clearinghint';
    } else if ($isreplace && (int) $job->totalmatched === 0) {
        $hint = 'analysinghint';
    } else {
        $hint = 'runninghint';
    }
    echo $OUTPUT->notification(get_string($hint, 'tool_imageextractor'), 'info');
}

// An analysed replace job awaits review: show the exact preview (read from
// the prepared targets - no file-table scan) and the final confirmation.
if ($isreplace && $job->status === manager::STATUS_REVIEW) {
    $review = manager::review_summary($id);
    echo $OUTPUT->notification(get_string('replacewarning', 'tool_imageextractor'), 'error');
    echo $OUTPUT->heading(get_string('replacepreviewheading', 'tool_imageextractor'), 3);
    echo html_writer::div(get_string('reviewsummary', 'tool_imageextractor', (object) [
        'total'       => $review['total'],
        'willreplace' => $review['willreplace'],
        'willskip'    => $review['willskip'],
    ]), 'mb-2');
    // Visual preview: the first few targets with their current image and the
    // replacement shown side by side, so the outcome is obvious before applying.
    $thumbrows = array_slice($review['rows'], 0, 5);
    if ($thumbrows) {
        echo $OUTPUT->heading(get_string('previewthumbsheading', 'tool_imageextractor'), 4);
        $ttable = new html_table();
        $ttable->attributes['class'] = 'generaltable';
        $ttable->head = [
            get_string('colcurrentimage', 'tool_imageextractor'),
            get_string('colreplacementimage', 'tool_imageextractor'),
            get_string('colfilename', 'tool_imageextractor'),
        ];
        foreach ($thumbrows as $prow) {
            // The current image is served from its own component's pluginfile;
            // only build a URL for it when the target is actually an image.
            $oldurl = null;
            if (strpos((string) $prow->mimetype, 'image/') === 0) {
                $oldurl = moodle_url::make_pluginfile_url(
                    $prow->contextid,
                    $prow->component,
                    $prow->filearea,
                    $prow->fileitemid,
                    $prow->filepath,
                    $prow->filename
                );
            }
            // The replacement (when this target has one) comes from the job's
            // own replacement area.
            $newurl = null;
            if ($prow->replacementname !== null) {
                $newurl = moodle_url::make_pluginfile_url(
                    $context->id,
                    manager::COMPONENT,
                    'replacement',
                    $id,
                    '/',
                    $prow->replacementname
                );
            }
            $ttable->data[] = [
                tool_imageextractor_thumbnail($oldurl, get_string('colcurrentimage', 'tool_imageextractor')),
                tool_imageextractor_thumbnail($newurl, get_string('colreplacementimage', 'tool_imageextractor')),
                s($prow->filename),
            ];
        }
        echo html_writer::table($ttable);
    }

    if ($review['truncated']) {
        echo $OUTPUT->notification(
            get_string('reviewtruncated', 'tool_imageextractor', count($review['rows'])),
            'info'
        );
    }
    if ($review['rows']) {
        $ptable = new html_table();
        $ptable->attributes['class'] = 'generaltable';
        $ptable->head = [
            get_string('colfilename', 'tool_imageextractor'),
            get_string('component', 'tool_imageextractor'),
            get_string('filearea', 'tool_imageextractor'),
            get_string('colsize', 'tool_imageextractor'),
            get_string('replacement', 'tool_imageextractor'),
        ];
        foreach ($review['rows'] as $prow) {
            $ptable->data[] = [
                s($prow->filename),
                s($prow->component),
                s($prow->filearea),
                display_size((int) $prow->filesize),
                $prow->replacementname !== null
                    ? s($prow->replacementname)
                    : html_writer::tag('em', get_string('replacenomatchcell', 'tool_imageextractor')),
            ];
        }
        echo html_writer::table($ptable);
    }
    echo $OUTPUT->confirm(
        get_string('confirmreplacefinal', 'tool_imageextractor', $review['willreplace']),
        new moodle_url($viewurl, ['action' => 'run', 'confirm' => 1, 'sesskey' => sesskey()]),
        $viewurl
    );
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
            $id,
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
    // A replace job with restorable backups must be restored or cleared before
    // it can run again, so we hide Run until then (rerunning would otherwise
    // discard the backups of the original files). In review the confirm above
    // replaces the Run button ("Clear results" discards the analysis).
    $restorable = $isreplace && manager::has_restorable($id);
    if (!$restorable && $job->status !== manager::STATUS_REVIEW) {
        echo $OUTPUT->single_button(
            new moodle_url($viewurl, ['action' => 'run', 'sesskey' => sesskey()]),
            get_string('runjob', 'tool_imageextractor'),
            'get'
        );
    }
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/imageextractor/edit.php', ['id' => $id]),
        get_string('edit'),
        'get'
    );
    if ($restorable) {
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
