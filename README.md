# Image extractor (tool_imageextractor)

A Moodle administration tool for **finding images in the site's file storage**
and either **exporting** them (with metadata and naming rules) or
**replacing/restoring** them in bulk. It is built to handle very large result
sets (50 GB or more) by doing all heavy work in throttled, resumable background
tasks.

**Status:** beta (release `0.13.0-beta`). Feature-complete and CI-tested; suitable
for testing on non-production sites. See [`CHANGELOG.md`](CHANGELOG.md) for the
release history.

## Features

### Extract
- Search the Moodle file storage by course, course category, component, file
  area, MIME type, filename pattern, size range and creation date.
- Refine or drive the search with an uploaded **CSV**, interpreted as either:
  - a **scope list** of course, course-category or user identifiers,
  - a **match list** of exact filenames or content hashes (the listed files
    are selected wherever they are; the criteria fields are ignored), or
  - **per-row criteria**, where each row is its own search specification.
- **Naming rules** with placeholders (e.g. `{courseshortname}_{seq}_{originalname}`).
- Output is split into capped-size **ZIP volumes** so each archive can be
  downloaded through a browser, accompanied by a master **manifest CSV** and a
  per-image **JSON sidecar** of metadata.

### Replace / restore
Disabled by default; a site administrator opts in under the plugin settings.

- Select target files with the same criteria/CSV engine as Extract, with an
  extra **"only broken or missing files"** filter for fixing broken images.
- Two replacement strategies:
  - **Single image** applied to every match (e.g. rebranding a logo), or
  - a **ZIP of replacements** matched to targets by filename (e.g. swapping in
    watermarked versions of existing images).
- Each original is **backed up** before replacement, so the whole job can be
  **restored (undone)** later.
- Replacement preserves each file's logical location, so embedded references
  and pluginfile URLs keep working.

### Scale & safety
- All matching, packing, replacing and restoring run as **adhoc tasks**, one
  bounded batch/volume per run, re-queued until complete, with per-task
  concurrency caps.
- A master **kill-switch** plus a separate opt-in for the destructive
  replace/restore feature.
- Confirmation prompts before running or restoring; controls are hidden while a
  job is in flight.
- A scheduled **cleanup** task removes completed jobs and their archives after a
  configurable retention period.

## Requirements

- Moodle 5.0 or later (`$plugin->supported = [500, 502]`).
- PHP 8.2 or later.

## Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to
   _Site administration > Plugins > Install plugins_.
2. Upload the ZIP file. Choose the plugin type _Admin tool (tool)_ if prompted.
3. Continue and complete the upgrade.

## Installing manually

Copy the plugin into your Moodle install at:

    {your/moodle/dirroot}/admin/tool/imageextractor

Then log in as an admin and visit _Site administration > Notifications_ to
complete the installation.

## Usage

Go to _Site administration > Plugins > Admin tools > Image extractor_. The flow
is **select → analyse → results → extract or replace**:

1. **Create a job** describing only which files it selects — a name, the search
   criteria (course, category, component, file area, MIME type, filename
   pattern, size, date), an optional CSV, and the "only broken or missing
   files" refinement. No extract/replace choice is made here.
2. **Analyse** it from the job page. The matcher runs in the background (paged
   and throttled, so even huge sites cannot time out the page) and records the
   matched files. The job then shows **Results ready**.
3. **Review the results.** The job page shows how many files matched and their
   total size, a sample table, and thumbnails of the matched originals, with
   two action panels below.
4. **Choose an action:**
   - **Extract (download)** — set a naming rule and volume size and press
     _Download / Extract_. The matched files are packed into ZIP volumes with a
     manifest, which you download from the job page once packing finishes.
   - **Replace (upload)** — offered only to site administrators with the replace
     feature enabled. Upload a single replacement image or a ZIP matched by
     filename, choose whether to back up originals, and continue. A final
     screen shows a **thumbnail comparison of the current image and its
     replacement side by side** and an "are you sure" confirmation before the
     destructive apply is queued. Targets with no matching replacement are
     skipped.

Background tasks run on cron, so ensure cron is configured.

> **Note:** per-content-hash de-duplication is temporarily not applied — every
> matched file is packed. Restoring it (at pack time) is a planned follow-up.

Removing a previous run's results — whether by Run, Analyse, "Clear results"
or editing the job — never happens in the web request: on a large site that
delete can take minutes. It runs in a background task on the next cron
instead (the job shows **Clearing** briefly), so none of these actions can
time out at the gateway.

### Command line

Large jobs can also be driven from the CLI (handy when waiting on cron is
awkward, or for scripting):

```bash
# List jobs:
php admin/tool/imageextractor/cli/run_job.php --list

# Dry run (estimate for extract, preview for replace) — changes nothing:
php admin/tool/imageextractor/cli/run_job.php --jobid=5

# Extract job 5 to completion now:
php admin/tool/imageextractor/cli/run_job.php --jobid=5 --execute

# Run a destructive replace job (requires both --execute and --confirm):
php admin/tool/imageextractor/cli/run_job.php --jobid=7 --execute --confirm
```

## Configuration

_Site administration > Plugins > Admin tools > Image extractor > Settings_:

- **Enable image extraction** — master kill-switch.
- **Allow replace/restore** — opt-in for the destructive replace feature.
- **Default volume size (MB)** — default ZIP volume cap.
- **Processing / Replace concurrency** — how many background tasks run at once.
- **Batch size** — how many files each background batch processes before
  re-queuing (default 50). Smaller batches keep each burst of database work
  short.
- **Throttle delay (seconds)** — how long to leave the database idle between
  batches (default 20; 0 = process as fast as possible). On a small or shared
  server this keeps a running job from saturating the database and slowing the
  rest of the site; a large job simply takes longer. Every phase of a replace
  job is paced this way — matching the targets, clearing a previous run, and
  applying — so no single phase pins the database in one pass.
- **Retention period (days)** — auto-cleanup window for completed jobs.

## Privacy

The plugin stores only the administrator who authored each job. The generated
archives can contain other users' images; these are system aggregates with no
per-user attribution path and are removed entirely when the system context is
purged. See `classes/privacy/provider.php`.

## License

Copyright (c) Skin Cancer College Australasia. All rights reserved.

This is a **proprietary** plugin. It is NOT free software and is NOT released
under the GNU General Public License. Unauthorised copying, distribution,
modification, or use is strictly prohibited without the prior written
permission of Skin Cancer College Australasia. See the `LICENSE` file.
