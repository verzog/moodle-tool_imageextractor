# Image extractor (tool_imageextractor)

A Moodle administration tool for **finding images in the site's file storage**
and either **exporting** them (with metadata and naming rules) or
**replacing/restoring** them in bulk. It is built to handle very large result
sets (50 GB or more) by doing all heavy work in throttled, resumable background
tasks.

**Status:** beta (release `0.4.0-beta`). Feature-complete and CI-tested; suitable
for testing on non-production sites. See [`CHANGELOG.md`](CHANGELOG.md) for the
release history.

## Features

### Extract
- Search the Moodle file storage by component, file area, MIME type, filename
  pattern, size range and creation date.
- Refine or drive the search with an uploaded **CSV**, interpreted as either:
  - a **scope list** of course or user identifiers,
  - a **match list** of exact filenames or content hashes, or
  - **per-row criteria**, where each row is its own search specification.
- **Naming rules** with placeholders (e.g. `{courseshortname}_{seq}_{originalname}`).
- Optional de-duplication so each image is exported only once.
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

Go to _Site administration > Plugins > Admin tools > Image extractor_. Create a
job, review the estimated match count, then run it. Background tasks run on
cron, so ensure cron is configured. Download the generated ZIP volumes and
manifest from the job's view page.

Running a replace job from the web shows a warning, a preview of the files that
would be overwritten, and an "are you sure" confirmation; it is restricted to
site administrators.

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
