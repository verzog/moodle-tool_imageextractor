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
 * CLI: run an image extraction or replace job from the command line.
 *
 * Lists jobs, or queues a job and (optionally) processes it to completion
 * inline - useful for very large extractions where waiting on cron is awkward.
 * Replace jobs are destructive (they overwrite live files) and therefore
 * require both --execute and --confirm.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_imageextractor\manager;
use tool_imageextractor\replacer;

[$options, $unrecognised] = cli_get_params(
    [
        'help'    => false,
        'list'    => false,
        'jobid'   => 0,
        'execute' => false,
        'confirm' => false,
    ],
    [
        'h' => 'help',
        'l' => 'list',
        'j' => 'jobid',
        'e' => 'execute',
        'c' => 'confirm',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    echo "Run an image extraction or replace job.

By default a job is only queued (it then runs on cron, exactly like the web
'Run' button). Pass --execute to also process it to completion inline now.

Options:
  -h, --help       Print this help.
  -l, --list       List all jobs (id, type, status, name) and exit.
  -j, --jobid=ID   The job to act on.
  -e, --execute    Process the job inline now, not just queue it.
  -c, --confirm    Required, with --execute, to run a destructive REPLACE job.

Examples:
  # List jobs:
  \$ php admin/tool/imageextractor/cli/run_job.php --list

  # Queue extraction job 5 (cron will process it):
  \$ php admin/tool/imageextractor/cli/run_job.php --jobid=5

  # Extract job 5 to completion right now:
  \$ php admin/tool/imageextractor/cli/run_job.php --jobid=5 --execute

  # Run a replace job (destructive - rewrites live files):
  \$ php admin/tool/imageextractor/cli/run_job.php --jobid=7 --execute --confirm
";
    exit(0);
}

if (!manager::is_enabled()) {
    cli_error("The image extractor is switched off. Enable it in "
        . "Site administration > Plugins > Admin tools > Image extractor > Settings first.");
}

if ($options['list']) {
    $jobs = manager::get_jobs();
    if (!$jobs) {
        cli_writeln("No jobs found.");
        exit(0);
    }
    cli_writeln(count($jobs) . " job(s):");
    foreach ($jobs as $job) {
        cli_writeln(sprintf(
            '  #%d  [%s]  %s  -  %s',
            $job->id,
            $job->jobtype,
            $job->status,
            $job->name
        ));
    }
    exit(0);
}

$jobid = (int) $options['jobid'];
if ($jobid <= 0) {
    cli_error("Pass --jobid=ID (use --list to see jobs), or --help.");
}

$job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid]);
if (!$job) {
    cli_error("No job with id {$jobid}. Use --list.");
}

$isreplace = ($job->jobtype === 'replace');
if ($isreplace && !manager::is_replace_allowed()) {
    cli_error("Replace/restore is disabled. Enable 'Allow replace/restore' in the plugin settings first.");
}

// Dry run: report what would happen without queuing or changing anything.
if (empty($options['execute'])) {
    if ($isreplace) {
        if ($job->status === manager::STATUS_REVIEW) {
            // Already analysed (via the web Run button): report the stored total
            // instead of re-scanning the file table. The replace/skip split is
            // resolved per target at apply time, not counted here (counting the
            // freshly analysed item table would scan millions of unvacuumed rows
            // and stall), so report the total and how the split is decided.
            $review = manager::review_summary($jobid);
            cli_writeln("Replace job #{$jobid} \"{$job->name}\" (analysed, awaiting review):");
            cli_writeln("  matched by criteria: {$review['total']}");
            cli_writeln("  each target's replacement is resolved at apply time; "
                . "targets with no matching replacement are skipped then.");
        } else {
            $preview = (new replacer($job))->preview();
            cli_writeln("Replace job #{$jobid} \"{$job->name}\" (dry run):");
            cli_writeln("  matched by criteria: {$preview['total']}");
            cli_writeln("  of the first {$preview['scanned']} checked: "
                . "{$preview['willreplace']} would be replaced, {$preview['willskip']} skipped (no replacement)");
        }
        cli_writeln("Re-run with --execute --confirm to apply (this overwrites live files).");
    } else {
        $estimate = manager::estimate($job);
        cli_writeln("Extraction job #{$jobid} \"{$job->name}\" (dry run):");
        cli_writeln("  matches {$estimate['count']} files (" . display_size($estimate['bytes']) . ")");
        cli_writeln("Re-run with --execute to process now, or it will run on cron once queued.");
    }
    exit(0);
}

// Destructive replace jobs need an explicit second flag.
if ($isreplace && empty($options['confirm'])) {
    cli_error("Refusing to run a REPLACE job without --confirm. This permanently overwrites "
        . "live files across the whole site. Add --confirm to proceed.");
}

\core_php_time_limit::raise(0);
\raise_memory_limit(MEMORY_EXTRA);

cli_writeln("Queuing job #{$jobid} ...");
manager::queue_job($jobid);

// Drain this job's adhoc tasks inline until it reaches a terminal state. Each
// task processes one batch/volume and re-queues itself, so we loop.
$class = $isreplace
    ? '\tool_imageextractor\task\process_replace'
    : '\tool_imageextractor\task\process_job';
$guard = 0;
while (true) {
    $job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid], '*', MUST_EXIST);
    if (!in_array($job->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true)) {
        break;
    }
    if (++$guard > 100000) {
        cli_error("Aborting: job did not reach a terminal state after many iterations.");
    }
    $ran = false;
    foreach (\core\task\manager::get_adhoc_tasks($class) as $task) {
        $data = (object) $task->get_custom_data();
        if ((int) ($data->jobid ?? 0) !== $jobid) {
            continue;
        }
        \core\task\manager::adhoc_task_starting($task);
        $task->execute();
        \core\task\manager::adhoc_task_complete($task);
        $ran = true;
    }
    if (!$ran) {
        // No queued task for this job but it is still not terminal - avoid
        // spinning forever (e.g. the task was claimed by a concurrent cron run).
        cli_writeln("No runnable task for this job right now; leaving it for cron.");
        break;
    }
}

$job = $DB->get_record('tool_imageextractor_job', ['id' => $jobid], '*', MUST_EXIST);
cli_writeln("Done. Job #{$jobid} status: {$job->status}"
    . " (processed {$job->processedcount}/{$job->totalmatched}, failed {$job->failedcount}).");
exit(0);
