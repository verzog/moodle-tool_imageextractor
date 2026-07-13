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
 * Language strings (en) - Australian English usage throughout.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

$string['actions'] = 'Actions';
$string['analysejob'] = 'Analyse';
$string['analysequeued'] = 'Analysis queued. Matching files are being identified in the background; reload this page to review the results once it finishes.';
$string['analysing'] = 'Analysing…';
$string['analysinghint'] = 'Analysing matching files in the background. This changes nothing; reload this page to check progress and review the results once it finishes.';
$string['backtojobs'] = 'Back to jobs';
$string['backup'] = 'Back up originals';
$string['backup_help'] = 'Keep a copy of each original file before replacing it, so the replacement can be undone with Restore.';
$string['cannoteditrunning'] = 'This job cannot be edited while it is running or clearing. Wait for it to finish (or clear its results) first.';
$string['cannotrerunwithbackups'] = 'This replace job still has restorable backups. Restore or clear them before running it again.';
$string['categories'] = 'Course categories';
$string['categories_help'] = 'Limit the search to files in the selected course categories, including every subcategory and course beneath them. Leave empty to search the whole site. Categories are combined (union) with any courses chosen above - a file matches when it is in any selected course or category.';
$string['clearing'] = 'Clearing…';
$string['clearinghint'] = 'Removing this job\'s previous results in the background. Reload this page to check progress; the job returns to draft when it finishes.';
$string['clearqueued'] = 'Clearing queued. The previous results are being removed in the background and the job will return to draft shortly.';
$string['clearresults'] = 'Clear results';
$string['colcurrentimage'] = 'Current image';
$string['colfilename'] = 'File name';
$string['colreplacementimage'] = 'Replacement image';
$string['colsize'] = 'Size';
$string['component'] = 'Component';
$string['confirmanalyse'] = 'Analyse the files this job selects? The scan runs in the background and changes nothing; you review the matched files and then choose to extract or replace them.';
$string['confirmdelete'] = 'Delete the job "{$a}" and all of its generated archives? This cannot be undone.';
$string['confirmreplacefinal'] = 'Replace {$a} files across the live site now? Originals are backed up where possible and can be reverted with Restore, but this otherwise cannot be undone.';
$string['confirmrestore'] = 'Restore every replaced file in this job from its backup? This undoes the replacement.';
$string['courses'] = 'Courses';
$string['courses_help'] = 'Limit the search to files that belong to the selected courses, including files in each course\'s activities and blocks. Leave empty to search the whole site. Courses chosen here are combined with any courses listed in an uploaded scope CSV.';
$string['criteria'] = 'Search criteria';
$string['csvfile'] = 'CSV file';
$string['csvmode'] = 'Select files using';
$string['csvmode_criteria'] = 'A CSV of per-row criteria (each row is a search specification)';
$string['csvmode_help'] = 'Files can be selected by the criteria fields below, or driven by an uploaded CSV. A scope list limits the criteria search to the listed courses, course categories or users (recognised from a courseid, category or username/email column, or a bare course identifier in the first column). A match list selects exactly the listed files - by filename or content hash, wherever they are - and the other criteria are ignored. Per-row criteria treats each row as its own search specification, combined with OR and refined by the criteria fields.';
$string['csvmode_match'] = 'A CSV match list (exact filenames or content hashes)';
$string['csvmode_none'] = 'The search criteria below';
$string['csvmode_scope'] = 'A CSV scope list (course, category or user identifiers)';
$string['csvunknowncategory'] = 'CSV: could not match a course category for "{$a}" - row skipped.';
$string['csvunknowncourse'] = 'CSV: could not match a course for "{$a}" - row skipped.';
$string['csvunknownuser'] = 'CSV: could not match a user for "{$a}" - row skipped.';
$string['datefrom'] = 'Created on or after';
$string['dateto'] = 'Created on or before';
$string['disabledretry'] = 'Image extractor is disabled; the job will resume automatically once it is re-enabled.';
$string['disabledwarning'] = 'The image extractor is currently disabled in the plugin settings. Jobs cannot run until it is re-enabled.';
$string['downloads'] = 'Downloads';
$string['editjob'] = 'Edit job';
$string['errorcsvrequired'] = 'Upload a CSV file, or select "The search criteria below".';
$string['errordaterange'] = 'The "created on or before" date must be on or after the "created on or after" date.';
$string['errornoreplacement'] = 'Please upload a replacement image (or ZIP) for this replace job.';
$string['errorsizerange'] = 'The maximum size must be greater than or equal to the minimum size.';
$string['errorvolumesize'] = 'The volume size must be at least 1 MB.';
$string['extractdownload'] = 'Download / Extract';
$string['extractpanelheading'] = 'Extract (download)';
$string['extractpanelintro'] = 'Pack the matched files into downloadable ZIP volumes with a manifest.';
$string['extractqueued'] = 'Extraction queued. The matched files are being packed in the background; reload this page to download the volumes once it finishes.';
$string['failedcount'] = 'Files skipped (missing or unreadable)';
$string['filearea'] = 'File area';
$string['filenamepattern'] = 'Filename pattern';
$string['filenamepattern_help'] = 'Match filenames against this pattern. Use * as a wildcard, for example *.jpg or photo*.';
$string['files'] = 'files';
$string['imageextractor:manage'] = 'Manage image extraction and replacement jobs';
$string['imageonly'] = 'Images only';
$string['imageonly_help'] = 'Restrict the search to image files (recommended).';
$string['jobdeleted'] = 'Job deleted.';
$string['jobdescription'] = 'Description';
$string['joberror'] = 'The last run failed: {$a}';
$string['jobname'] = 'Job name';
$string['jobqueued'] = 'Job queued. It will run in the background via cron.';
$string['jobs'] = 'Jobs';
$string['jobsaved'] = 'Job saved.';
$string['jobstatus_clearing'] = 'Clearing';
$string['jobstatus_completed'] = 'Completed';
$string['jobstatus_draft'] = 'Draft';
$string['jobstatus_failed'] = 'Failed';
$string['jobstatus_processing'] = 'Processing';
$string['jobstatus_queued'] = 'Queued';
$string['jobstatus_review'] = 'Results ready';
$string['jobtype'] = 'Type';
$string['jobtype_extract'] = 'Extract';
$string['jobtype_replace'] = 'Replace';
$string['jobtype_unset'] = 'Not chosen yet';
$string['manifest'] = 'Manifest (CSV)';
$string['maxsizekb'] = 'Maximum size (KB)';
$string['mimetypes'] = 'MIME types';
$string['mimetypes_help'] = 'A comma-separated list of MIME types or prefixes to match, for example image/jpeg, image/png. Leave blank to accept all images.';
$string['minsizekb'] = 'Minimum size (KB)';
$string['missingonly'] = 'Only broken or missing files';
$string['missingonly_help'] = 'Limit the targets to files whose stored content is missing or unreadable - useful for fixing broken images.';
$string['namingrule'] = 'Naming rule';
$string['namingrule_help'] = 'A template for output filenames. Available placeholders: {originalname}, {originalbase}, {ext}, {fileid}, {contenthash}, {contenthash8}, {component}, {filearea}, {itemid}, {courseid}, {coursename}, {courseshortname}, {uploaderid}, {mimetype}, {seq}, {date}. The original extension is added automatically if your template omits it.';
$string['newjob'] = 'New job';
$string['nobackup'] = 'No backup was available to restore.';
$string['nojobs'] = 'No jobs have been created yet.';
$string['noreplacement'] = 'No matching replacement was found for this file.';
$string['nothumbnail'] = 'No preview';
$string['output'] = 'Output';
$string['pluginname'] = 'Image extractor';
$string['previewthumbsheading'] = 'Sample preview (current vs replacement)';
$string['privacy:metadata:item'] = 'One row per matched file, recording metadata about files captured by a job.';
$string['privacy:metadata:item:contenthash'] = 'The content hash of the matched file.';
$string['privacy:metadata:item:filename'] = 'The name of the matched file.';
$string['privacy:metadata:item:filetimecreated'] = 'When the matched file was created.';
$string['privacy:metadata:item:uploaderid'] = 'The user who uploaded the matched file.';
$string['privacy:metadata:job'] = 'Image extraction and replacement jobs created by administrators.';
$string['privacy:metadata:job:name'] = 'The name of the job.';
$string['privacy:metadata:job:timemodified'] = 'When the job was last modified.';
$string['privacy:metadata:job:usermodified'] = 'The administrator who created or last edited the job.';
$string['privacy:path:items'] = 'Captured files';
$string['progress'] = 'Progress';
$string['replaceadminonly'] = 'Only a site administrator can run a replace or restore job, because it overwrites live files across the whole site.';
$string['replacecontinue'] = 'Replace';
$string['replaced'] = 'Replaced';
$string['replacedisabled'] = 'Replace/restore jobs are disabled. A site administrator must enable them in the plugin settings.';
$string['replacement'] = 'Replacement';
$string['replacementfile'] = 'Replacement image';
$string['replacementzip'] = 'Replacement ZIP';
$string['replacemode'] = 'Replacement source';
$string['replacemode_help'] = 'Single uses one uploaded image for every matched file (for example, a new brand logo). ZIP uses an uploaded archive of images, matching each target to the archive entry with the same filename (for example, watermarked versions of existing images).';
$string['replacemode_single'] = 'Single image for all matches';
$string['replacemode_zip'] = 'ZIP of replacements matched by filename';
$string['replacenomatchcell'] = 'no matching replacement';
$string['replacepanelheading'] = 'Replace (upload)';
$string['replacepanelintro'] = 'Overwrite the matched files across the live site with an uploaded image or ZIP. Originals are backed up (when enabled) so the change can be undone. You confirm before anything is written.';
$string['replacepreviewheading'] = 'Files that would be replaced';
$string['replacewarning'] = 'Warning: this permanently overwrites the content of matching files across the entire site - all courses and users, not just one. Originals are backed up only when "Back up originals" is enabled. Review the list below before continuing.';
$string['restored'] = 'Restored';
$string['restorejob'] = 'Restore (undo)';
$string['restorequeued'] = 'Restore queued. Originals will be put back in the background via cron.';
$string['restorewarning'] = 'Restoring overwrites the current (replaced) files with the backed-up originals across the site. Continue only if you want to undo this job\'s replacements.';
$string['resultscleared'] = 'Results cleared.';
$string['resultsheading'] = 'Results';
$string['resultssummary'] = 'Analysis complete: {$a->count} files matched ({$a->size}). Choose what to do with them below.';
$string['reviewsummary'] = 'All {$a->total} matching files have been analysed: {$a->willreplace} will be replaced and {$a->willskip} will be skipped (no matching replacement).';
$string['reviewtruncated'] = 'Showing the first {$a} of the matched files; the run processes every match.';
$string['runninghint'] = 'This job is running in the background. Reload this page to see updated progress.';
$string['setting_allow_replace'] = 'Allow replace/restore';
$string['setting_allow_replace_desc'] = 'Replace and restore jobs rewrite live site files. Leave this unticked unless you intend to use them; unticking it also stops any in-flight replace tasks.';
$string['setting_batch_size'] = 'Batch size';
$string['setting_batch_size_desc'] = 'How many files a background job processes per batch before pausing and re-queuing. A smaller batch keeps each burst of database work short so a running job does not overload the database on a small or shared server. For extract jobs this also caps how many files go into each ZIP volume, so a very small batch produces more (smaller) volumes. Default 50.';
$string['setting_default_volume_mb'] = 'Default volume size (MB)';
$string['setting_default_volume_mb_desc'] = 'The ZIP volume size offered by default on the job form. Large result sets are split into volumes of this size so each archive can be downloaded through a browser.';
$string['setting_enabled'] = 'Enable image extraction';
$string['setting_enabled_desc'] = 'Master switch. When unticked, no job will run and any queued background tasks stop without doing work.';
$string['setting_process_concurrency'] = 'Processing concurrency';
$string['setting_process_concurrency_desc'] = 'How many extraction tasks may run at once. Packing archives is IO-heavy, so a low value keeps a large job from starving other scheduled tasks.';
$string['setting_replace_concurrency'] = 'Replace concurrency';
$string['setting_replace_concurrency_desc'] = 'How many replace tasks may run at once. Replacing files writes to the file storage, so a low value avoids contention.';
$string['setting_retention_days'] = 'Retention period (days)';
$string['setting_retention_days_desc'] = 'How many days completed jobs and their archives are kept before being removed automatically. Set to 0 to keep them indefinitely.';
$string['setting_throttle_delay'] = 'Throttle delay (seconds)';
$string['setting_throttle_delay_desc'] = 'How many seconds to leave the database idle between batches. Re-queuing the next batch this far in the future (rather than processing batches back-to-back) lets ordinary page requests run while a large job is in progress, so the site stays responsive. Set to 0 to process as fast as possible (heaviest on the database). Default 20.';
$string['settings'] = 'Settings';
$string['status'] = 'Status';
$string['targetcriteria'] = 'Target files';
$string['task_cleanup'] = 'Remove expired image extraction jobs';
$string['timecreated'] = 'Created';
$string['totalmatched'] = 'Matched files';
$string['volumemb'] = 'Volume size (MB)';
$string['volumemb_help'] = 'The maximum size of each ZIP volume. A browser cannot reliably download a single very large archive, so the export is split into volumes no larger than this.';
