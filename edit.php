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
 * Create or edit a job's file selection.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_imageextractor\manager;
use tool_imageextractor\form\job_form;
use tool_imageextractor\form\criteria_fields;

$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('tool_imageextractor');

$context = context_system::instance();
require_capability('tool/imageextractor:manage', $context);

$job = $id ? manager::get_job($id) : null;

// Editing a job while it is queued, processing or clearing would change its
// definition mid-flight - a paged match would then record some pages with the
// old criteria and later pages with the new ones. Refuse until it settles.
$busystatuses = [manager::STATUS_QUEUED, manager::STATUS_PROCESSING, manager::STATUS_CLEARING];
if ($job && in_array($job->status, $busystatuses, true)) {
    \core\notification::error(get_string('cannoteditrunning', 'tool_imageextractor'));
    redirect(new moodle_url('/admin/tool/imageextractor/view.php', ['id' => $id]));
}

$editurl = new moodle_url('/admin/tool/imageextractor/edit.php', $id ? ['id' => $id] : []);
$indexurl = new moodle_url('/admin/tool/imageextractor/index.php');
$PAGE->set_url($editurl);
$PAGE->set_title(get_string('editjob', 'tool_imageextractor'));
$PAGE->set_heading(get_string('editjob', 'tool_imageextractor'));

$mform = new job_form($editurl->out(false), ['id' => $id]);

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
        'missingonly' => $job->missingonly ? 1 : 0,
        'altmissing'  => $job->altmissing ? 1 : 0,
    ]);
    $mform->set_data($defaults);
}

if ($data = $mform->get_data()) {
    $result = manager::save_job($data);
    foreach ($result['warnings'] as $warning) {
        \core\notification::warning($warning);
    }
    \core\notification::success(get_string('jobsaved', 'tool_imageextractor'));
    redirect(new moodle_url('/admin/tool/imageextractor/view.php', ['id' => $result['id']]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading($job ? get_string('editjob', 'tool_imageextractor')
    : get_string('newjob', 'tool_imageextractor'));
$mform->display();
echo $OUTPUT->footer();
