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
 * View one job: analyse it, review the matched files, then extract or replace.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use tool_imageextractor\manager;
use tool_imageextractor\form\extract_form;
use tool_imageextractor\form\replace_form;

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
$isreview = ($job->status === manager::STATUS_REVIEW);
// Replacing live files is destructive, so the Replace action is offered only to
// site administrators with the feature switched on; the panel and every action
// handler below enforce this rule server-side.
$allowreplace = manager::is_replace_allowed() && is_siteadmin();
// A missing-only analysis selects files precisely because their content is
// broken or unreadable, so packing them would only re-fail: no Extract panel
// for those jobs (they can still be replaced or restored).
$canextract = empty($job->missingonly);
// Per-row-criteria CSV jobs stay extract-only: each row widens the destructive
// selection, which is too easy to get wrong for a replace. Enforced here (panel
// hidden) and again in every replace action handler below.
$replaceallowed = $allowreplace && $job->csvmode !== 'criteria';

// The results page presents the extract and replace action panels. They are
// built whenever the job is awaiting an action so their submissions can be
// processed here (each moodleform recognises its own submission).
$extractform = null;
$replaceform = null;
if ($isreview) {
    if ($canextract) {
        $extractform = new extract_form($viewurl->out(false), ['id' => $id]);
        // Seed the panel from the job's stored options only when it already has
        // an extract action chosen, so an existing or upgraded extract job shows
        // its own naming rule and volume size. A freshly analysed criteria-only
        // job (jobtype '') carries just the schema defaults, not admin choices,
        // so seeding them would override extract_form's configured defaults
        // (e.g. a site's custom default volume size) - leave those to the form.
        $extractdata = ['id' => $id];
        if ($job->jobtype === 'extract') {
            if ((string) $job->namingrule !== '') {
                $extractdata['namingrule'] = $job->namingrule;
            }
            if ((int) $job->volumesize > 0) {
                $extractdata['volumemb'] = (int) round($job->volumesize / 1024 / 1024);
            }
        }
        $extractform->set_data($extractdata);
    }
    if ($replaceallowed) {
        // Let an existing/upgraded replace job that already has a stored source
        // for the chosen mode reuse it instead of demanding a fresh upload.
        $hasstoredsource = (bool) get_file_storage()->get_area_files(
            $context->id,
            manager::COMPONENT,
            'replacement',
            $id,
            'id',
            false
        );
        $replaceform = new replace_form($viewurl->out(false), [
            'id'                => $id,
            'storedreplacemode' => $job->replacemode,
            'hasstoredsource'   => $hasstoredsource,
        ]);
        $replaceform->set_data(['id' => $id]);
        // Drive the resumable chunked-upload widget rendered inside the panel.
        $PAGE->requires->js(new moodle_url('/admin/tool/imageextractor/js/chunkupload.js'));
    }
}

// Extract panel: store the output options and queue the packing of the
// already-matched items.
if ($extractform && ($data = $extractform->get_data())) {
    if (!manager::is_enabled()) {
        \core\notification::error(get_string('disabledwarning', 'tool_imageextractor'));
        redirect($viewurl);
    }
    manager::set_extract_action($id, (string) $data->namingrule, (int) $data->volumemb);
    manager::queue_extract($id);
    \core\notification::success(get_string('extractqueued', 'tool_imageextractor'));
    redirect($viewurl);
}

// Replace panel: enforce the admin-only rule, store the replacement source, set
// the job to replace, then go to the final destructive confirmation.
if ($replaceform && ($data = $replaceform->get_data())) {
    if (!manager::is_enabled() || !manager::is_replace_allowed() || !is_siteadmin()) {
        \core\notification::error(get_string('replaceadminonly', 'tool_imageextractor'));
        redirect($viewurl);
    }
    if ($job->csvmode === 'criteria') {
        \core\notification::error(get_string('errorcsvcriteriareplace', 'tool_imageextractor'));
        redirect($viewurl);
    }
    manager::set_replace_action($id, $data);
    redirect(new moodle_url($viewurl, ['action' => 'replaceconfirm', 'sesskey' => sesskey()]));
}

// Handle keyed actions.
if ($action !== '' && confirm_sesskey()) {
    // Analyse a draft job: match the files it selects in the background, then
    // return here with the results and the extract/replace panels.
    if ($action === 'analyse' && $job->status === manager::STATUS_DRAFT) {
        if (!manager::is_enabled()) {
            \core\notification::error(get_string('disabledwarning', 'tool_imageextractor'));
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
            new moodle_url($viewurl, ['action' => 'analyse', 'confirm' => 1, 'sesskey' => sesskey()]),
            $viewurl
        );
        echo $OUTPUT->footer();
        die();
    }

    // Final destructive confirmation for a replace, after the source is stored.
    if ($action === 'replaceconfirm' && $isreview && $isreplace) {
        if (!manager::is_enabled() || !manager::is_replace_allowed() || !is_siteadmin()) {
            \core\notification::error(get_string('replaceadminonly', 'tool_imageextractor'));
            redirect($viewurl);
        }
        if ($job->csvmode === 'criteria') {
            \core\notification::error(get_string('errorcsvcriteriareplace', 'tool_imageextractor'));
            redirect($viewurl);
        }
        // Only single/zip modes rewrite live image content; metadata and
        // alt-text modes leave the files intact, so each gets a gentler
        // warning and its own confirmation wording.
        $warnings = [
            'metadata' => ['metadatawarning', 'confirmmetadatafinal', 'warning'],
            'alttext'  => ['altwarning', 'confirmaltfinal', 'warning'],
        ];
        [$warnkey, $confirmkey, $notiftype] = $warnings[$job->replacemode]
            ?? ['replacewarning', 'confirmreplacefinal', 'error'];
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string($warnkey, 'tool_imageextractor'), $notiftype);
        tool_imageextractor_render_replace_preview($job, $viewurl);
        echo $OUTPUT->confirm(
            get_string($confirmkey, 'tool_imageextractor', (int) $job->totalmatched),
            new moodle_url($viewurl, ['action' => 'replacerun', 'confirm' => 1, 'sesskey' => sesskey()]),
            $viewurl
        );
        echo $OUTPUT->footer();
        die();
    }

    // Confirmed replace: queue the destructive apply of the matched items.
    if ($action === 'replacerun' && $confirm && $isreview && $isreplace) {
        if (!manager::is_enabled() || !manager::is_replace_allowed() || !is_siteadmin()) {
            \core\notification::error(get_string('replaceadminonly', 'tool_imageextractor'));
            redirect($viewurl);
        }
        if ($job->csvmode === 'criteria') {
            \core\notification::error(get_string('errorcsvcriteriareplace', 'tool_imageextractor'));
            redirect($viewurl);
        }
        manager::queue_job($id);
        \core\notification::success(get_string('jobqueued', 'tool_imageextractor'));
        redirect($viewurl);
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
            // A job holding millions of item rows is deleted in the background
            // so this request cannot time out; a light one is deleted inline.
            $deferred = manager::delete_job($id);
            \core\notification::success(get_string(
                $deferred ? 'deletequeued' : 'jobdeleted',
                'tool_imageextractor'
            ));
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

// While a job runs in the background, refresh the page periodically so the
// progress bar and status advance without the admin hammering reload. Set only
// for this main render - the confirmation screens above must not refresh away.
if ($running) {
    $PAGE->set_periodic_refresh_delay(20);
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
    $job->jobtype !== '' ? get_string('jobtype_' . $job->jobtype, 'tool_imageextractor')
        : get_string('jobtype_unset', 'tool_imageextractor')];
$summary->data[] = [get_string('status', 'tool_imageextractor'),
    get_string('jobstatus_' . $job->status, 'tool_imageextractor')];
if ($job->description !== '') {
    $summary->data[] = [get_string('jobdescription', 'tool_imageextractor'),
        format_text($job->description, FORMAT_PLAIN)];
}
if ($isreplace) {
    $summary->data[] = [get_string('replacemode', 'tool_imageextractor'),
        get_string('replacemode_' . $job->replacemode, 'tool_imageextractor')];
    if ($job->replacemode === 'metadata') {
        // Metadata-only: show what will be stamped; content backups do not
        // apply because no content is touched.
        if ((string) $job->metaauthor !== '') {
            $summary->data[] = [get_string('metaauthor', 'tool_imageextractor'), s($job->metaauthor)];
        }
        if ((string) $job->metalicense !== '') {
            $summary->data[] = [get_string('metalicense', 'tool_imageextractor'), s($job->metalicense)];
        }
    } else if ($job->replacemode !== 'alttext') {
        // Content replace (single/zip): show the uploaded source's file count,
        // backup and any optimization. Alt-text mode touches neither content
        // nor files, so it shows nothing.
        $replacementcount = manager::replacement_file_count($id);
        if ($replacementcount > 0) {
            // A ZIP source only replaces matched files whose basename it carries,
            // so surfacing "51 replacement images" against "407 matched files"
            // makes plain that the remainder are left unchanged.
            $summary->data[] = [get_string('replacementfilecount', 'tool_imageextractor'), $replacementcount];
        }
        $summary->data[] = [get_string('backup', 'tool_imageextractor'),
            $job->backup ? get_string('yes') : get_string('no')];
        if ((int) $job->optimizemaxpx > 0) {
            $summary->data[] = [get_string('optimize', 'tool_imageextractor'),
                get_string('optimizesummary', 'tool_imageextractor', (object) [
                    'px'      => (int) $job->optimizemaxpx,
                    'quality' => (int) $job->optimizequality,
                ])];
        }
    }
}
$summary->data[] = [get_string('missingonly', 'tool_imageextractor'),
    $job->missingonly ? get_string('yes') : get_string('no')];
if ($job->altmissing) {
    $summary->data[] = [get_string('altmissing', 'tool_imageextractor'), get_string('yes')];
}
if ($job->jobtype === 'extract') {
    $summary->data[] = [get_string('namingrule', 'tool_imageextractor'), s($job->namingrule)];
    $summary->data[] = [get_string('volumemb', 'tool_imageextractor'),
        display_size((int) $job->volumesize)];
}
if ((int) $job->totalmatched > 0) {
    $summary->data[] = [get_string('totalmatched', 'tool_imageextractor'),
        $job->totalmatched . ' (' . display_size((int) $job->totalbytes) . ')'];
    if ($job->jobtype !== '') {
        $summary->data[] = [get_string('progress', 'tool_imageextractor'),
            tool_imageextractor_progress_bar((int) $job->processedcount, (int) $job->totalmatched)
            . html_writer::div(display_size((int) $job->processedbytes), 'small')];
    }
}
if ((int) $job->failedcount > 0) {
    $summary->data[] = [get_string('failedcount', 'tool_imageextractor'), $job->failedcount];
}
echo html_writer::table($summary);

// The search criteria this job was defined with (course/category/activity
// scope resolved to names, plus every option set), so the definition is
// visible without opening the edit form.
$criteriarows = tool_imageextractor_criteria_rows($job);
if ($criteriarows) {
    echo $OUTPUT->heading(get_string('criteria', 'tool_imageextractor'), 3);
    $ctable = new html_table();
    $ctable->attributes['class'] = 'generaltable';
    $ctable->data = $criteriarows;
    echo html_writer::table($ctable);
}

if ($running) {
    // Clearing has its own hint; a job with no matched totals yet is still
    // analysing, and anything else is the generic processing hint.
    if ($job->status === manager::STATUS_CLEARING) {
        $hint = 'clearinghint';
    } else if ((int) $job->totalmatched === 0) {
        $hint = 'analysinghint';
    } else {
        $hint = 'runninghint';
    }
    echo $OUTPUT->notification(get_string($hint, 'tool_imageextractor'), 'info');

    // Live progress of the current background stage (clearing previous
    // results, or scanning for matching files).
    $stagebar = tool_imageextractor_stage_progress($job);
    if ($stagebar !== '') {
        echo html_writer::div($stagebar, 'mb-3', ['style' => 'max-width: 28em']);
    }
}

// Results page: the job is analysed and awaiting an action. Show the matched
// files and the extract/replace panels.
if ($isreview) {
    $review = manager::review_summary($id);
    echo $OUTPUT->heading(get_string('resultsheading', 'tool_imageextractor'), 3);
    echo html_writer::div(get_string('resultssummary', 'tool_imageextractor', (object) [
        'count' => (int) $job->totalmatched,
        'size'  => display_size((int) $job->totalbytes),
    ]), 'mb-2');

    // Thumbnails of the first few matched originals.
    $thumbrows = array_slice($review['rows'], 0, 8);
    $thumbs = [];
    foreach ($thumbrows as $prow) {
        $url = null;
        if (strpos((string) $prow->mimetype, 'image/') === 0) {
            $url = moodle_url::make_pluginfile_url(
                $prow->contextid,
                $prow->component,
                $prow->filearea,
                $prow->fileitemid,
                $prow->filepath,
                $prow->filename
            );
        }
        $thumbs[] = tool_imageextractor_thumbnail($url, $prow->filename);
    }
    if ($thumbs) {
        echo html_writer::div(implode('', $thumbs), 'd-flex flex-wrap gap-2 mb-3');
    }

    // Sample table of the matched files.
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
        ];
        foreach ($review['rows'] as $prow) {
            $ptable->data[] = [
                s($prow->filename),
                s($prow->component),
                s($prow->filearea),
                display_size((int) $prow->filesize),
            ];
        }
        echo html_writer::table($ptable);
    }

    // Action panels.
    if ($extractform) {
        echo $OUTPUT->heading(get_string('extractpanelheading', 'tool_imageextractor'), 4);
        echo html_writer::div(get_string('extractpanelintro', 'tool_imageextractor'), 'mb-2');
        $extractform->display();
    } else if ($job->missingonly) {
        echo $OUTPUT->notification(get_string('extractmissingonlynote', 'tool_imageextractor'), 'info');
    }

    if ($replaceform) {
        echo $OUTPUT->heading(get_string('replacepanelheading', 'tool_imageextractor'), 4);
        echo $OUTPUT->notification(get_string('replacepanelintro', 'tool_imageextractor'), 'warning');
        $replaceform->display();
    }
}

// Download links (extract jobs only).
$volumes = ($job->jobtype === 'extract') ? manager::get_volumes($id) : [];
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
    // A draft job is analysed first; a job with restorable backups must be
    // restored or cleared before anything else (rerunning would discard the
    // backups of the original files). At review the panels above drive the job.
    $restorable = $isreplace && manager::has_restorable($id);
    if ($job->status === manager::STATUS_DRAFT) {
        echo $OUTPUT->single_button(
            new moodle_url($viewurl, ['action' => 'analyse', 'sesskey' => sesskey()]),
            get_string('analysejob', 'tool_imageextractor'),
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
