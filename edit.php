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
 * Create or edit an extraction or replace job.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_imageextractor\manager;
use tool_imageextractor\matcher;
use tool_imageextractor\form\job_form;
use tool_imageextractor\form\criteria_fields;

$id = optional_param('id', 0, PARAM_INT);
$type = optional_param('type', 'extract', PARAM_ALPHA);

admin_externalpage_setup('tool_imageextractor');

$context = context_system::instance();
require_capability('tool/imageextractor:manage', $context);

$job = $id ? manager::get_job($id) : null;
if ($job) {
    $type = $job->jobtype;
}
$isreplace = ($type === 'replace');

if ($isreplace && !manager::is_replace_allowed()) {
    throw new moodle_exception('replacedisabled', 'tool_imageextractor');
}

$editurl = new moodle_url(
    '/admin/tool/imageextractor/edit.php',
    $id ? ['id' => $id] : ['type' => $type]
);
$indexurl = new moodle_url('/admin/tool/imageextractor/index.php');
$PAGE->set_url($editurl);
$PAGE->set_title(get_string('editjob', 'tool_imageextractor'));
$PAGE->set_heading(get_string('editjob', 'tool_imageextractor'));

// Replace jobs upload live content, so offering that type is restricted to
// site administrators with the feature switched on.
$allowreplace = manager::is_replace_allowed() && is_siteadmin();
if ($isreplace && !$allowreplace) {
    throw new moodle_exception('replaceadminonly', 'tool_imageextractor');
}

// Tell the form which replacement mode (if any) already has a stored source,
// so it can require a fresh upload when the mode is changed on edit.
$storedsource = false;
if ($job && $isreplace) {
    $storedsource = (bool) get_file_storage()->get_area_files(
        $context->id,
        manager::COMPONENT,
        'replacement',
        $job->id,
        'id',
        false
    );
}

$mform = new job_form($editurl->out(false), [
    'id'                => $id,
    'jobtype'           => $type,
    'allowreplace'      => $allowreplace,
    'storedreplacemode' => $job ? ($job->replacemode ?? '') : '',
    'hasstoredsource'   => $storedsource,
]);

if ($mform->is_cancelled()) {
    redirect($job ? new moodle_url('/admin/tool/imageextractor/view.php', ['id' => $id]) : $indexurl);
}

if ($job) {
    $criteria = manager::decode_criteria($job);
    $draftcsv = file_get_submitted_draft_itemid('csvfile');
    file_prepare_draft_area(
        $draftcsv,
        $context->id,
        manager::COMPONENT,
        'csv',
        $job->id,
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    $defaults = (object) array_merge(criteria_fields::defaults_from_criteria($criteria), [
        'id'          => $job->id,
        'name'        => $job->name,
        'description' => $job->description,
        'csvmode'     => $job->csvmode,
        'csvfile'     => $draftcsv,
    ]);

    if ($isreplace) {
        $draftreplacement = file_get_submitted_draft_itemid('replacementfile');
        file_prepare_draft_area(
            $draftreplacement,
            $context->id,
            manager::COMPONENT,
            'replacement',
            $job->id,
            ['subdirs' => 0, 'maxfiles' => 1]
        );
        $defaults->jobtype = 'replace';
        $defaults->missingonly = $job->missingonly ? 1 : 0;
        $defaults->replacemode = $job->replacemode;
        $defaults->replacementfile = $draftreplacement;
        $defaults->backup = $job->backup ? 1 : 0;
    } else {
        $defaults->namingrule = $job->namingrule;
        $defaults->dedupe = $job->dedupe ? 1 : 0;
        $defaults->volumemb = (int) ($job->volumesize / 1024 / 1024);
    }
    $mform->set_data($defaults);
}

if ($data = $mform->get_data()) {
    // The form only offers the replace type to permitted admins, but the
    // submitted value must be held to the same rule.
    if (($data->jobtype ?? 'extract') === 'replace' && !$allowreplace) {
        throw new moodle_exception('replaceadminonly', 'tool_imageextractor');
    }
    $result = manager::save_job($data);
    foreach ($result['warnings'] as $warning) {
        \core\notification::warning($warning);
    }
    \core\notification::success(get_string('jobsaved', 'tool_imageextractor'));
    redirect(new moodle_url('/admin/tool/imageextractor/view.php', ['id' => $result['id']]));
}

// Enhance the form with a live match-count estimate. The estimate region is
// extract-only; when the type selector is switched to replace the region is
// hidden by the form's hideIf rules and the module's updates are unseen.
$PAGE->requires->js_call_amd('tool_imageextractor/estimate', 'init');
// Show only the sections relevant to the selected job type (the individual
// fields are covered by hideIf; this hides the section headers too).
$PAGE->requires->js_call_amd('tool_imageextractor/jobtype', 'init');

$estimate = null;
if (!$isreplace && $mform->no_submit_button_pressed()) {
    $submitted = $mform->get_submitted_data();
    if ($submitted) {
        $criteria = manager::criteria_from_data($submitted);
        $estimate = (new matcher($criteria, !empty($submitted->dedupe)))->estimate();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($isreplace ? get_string('newreplacejob', 'tool_imageextractor')
    : get_string('newjob', 'tool_imageextractor'));
if ($estimate !== null) {
    echo $OUTPUT->notification(
        get_string('estimateresult', 'tool_imageextractor', [
            'count' => $estimate['count'],
            'size'  => display_size($estimate['bytes']),
        ]),
        \core\output\notification::NOTIFY_INFO
    );
}
$mform->display();
echo $OUTPUT->footer();
