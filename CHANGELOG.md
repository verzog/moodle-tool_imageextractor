# Changelog

All notable changes to **tool_imageextractor** are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and the project uses date-based Moodle build numbers (`$plugin->version`)
alongside a human-readable `$plugin->release` string.

## [0.18.1-beta] — 2026-07-15

Build `2026071504`.

### Added
- **The job view page now shows the search criteria the job was defined with** —
  a "Search criteria" table listing how files are selected (criteria or a CSV
  mode), the course/category/activity scope resolved to names, the activity
  name pattern, filename/MIME/size/date filters and the refinements set. Long
  course or category lists are capped with a "+N more" tail, and only options
  that were actually set are shown, so the job's definition is visible at a
  glance without opening the edit form.

## [0.18.0-beta] — 2026-07-15

Build `2026071503`.

### Added
- **Resumable chunked uploader for large replacement files.** A single ZIP (or
  image) bigger than the site's per-request upload limit can now be uploaded
  from the Replace panel: the browser slices it and posts it a chunk at a time
  to a new `upload.php` endpoint, which assembles it (in the file storage, so it
  survives across load-balanced app servers) straight into the job's replacement
  area — a ZIP is unpacked and matched by filename, any other file becomes the
  single replacement. Chunks are stored by index and re-counted server-side, so
  a retried or resumed chunk never double-counts. New `tool_imageextractor_upload`
  table, a `chunk_size_mb` setting (default 5), the assembler in a testable
  `chunk_uploader` class, and a plain-JS widget; abandoned uploads are purged by
  the cleanup task after a day. (The overall file has no size limit; each chunk
  just needs to fit under `upload_max_filesize`/`post_max_size`.)
- **Wider alt-text / description coverage.** The HTML locator now also finds
  images embedded in course-category descriptions, user profile descriptions,
  question general feedback and answers, essay grader info, data records, lesson
  answers/responses, wiki pages, and assignment online-text/feedback — so the
  alt-text export, the "missing description" audit and the alt-text replace reach
  those areas too.

## [0.17.0-beta] — 2026-07-15

Build `2026071502`.

### Added
- **"Only images with a missing description" search refinement.** A new job
  criterion (alongside "Only broken or missing files") keeps only images that
  are displayed in content through an `<img>` tag with an empty or missing
  `alt` attribute — a first-class accessibility audit filter. It is applied as
  a post-match refinement (the file table's SQL cannot see into HTML), mirroring
  how "missing only" works. Combine it with Extract to download a manifest of
  the undescribed images, or with the alt-text replace to give them
  descriptions. New `altmissing` job field.
- **Alt-text replaces are now reversible.** With "Back up originals" enabled
  (the default), an alt-text replace saves the original HTML of each changed
  field to a new `tool_imageextractor_htmlbackup` table, and Restore writes it
  back field-for-field before marking the job restored — the same admin-only,
  confirmed, backup-and-restore treatment as a content replace. The re-run
  guard applies too, so an applied alt-text job must be restored or cleared
  before it can run again.

## [0.16.0-beta] — 2026-07-15

Build `2026071501`.

### Added
- **Image descriptions (alt text) in the export.** The manifest CSV and the
  JSON sidecars now carry each image's description, read from the alt attribute
  of the `<img>` tag in the course content that embeds it (page and module
  content/intro, book chapters, lesson pages, forum posts, glossary entries,
  question text, course/section summaries). Images with no description show
  blank — so the manifest doubles as an accessibility audit of undescribed
  images. Stored on a new `alttext` item field.
- **Alt-text replace mode.** A fourth replace mode rewrites image descriptions
  in the course content that embeds them, from an uploaded `filename,alttext`
  CSV — the exported manifest edited in place drops straight back in. Only the
  matched `<img>` tags' alt attributes are touched (the rest of the markup is
  left byte-for-byte unchanged); image content is never altered. Admin-only
  with a confirmation and a preview of current-vs-new descriptions; the
  previous description is recorded per item. A new `htmllocator` class maps a
  file back to its embedding HTML field and reads/writes the alt text.

### Fixed
- **Restore now puts back the backup's own metadata** (author, licence,
  uploader, creation time) rather than leaving the replacement's metadata on
  the restored original — visible on jobs replaced before content-preservation
  existed. (Codex review.)
- **The captured author name is cleared on erasure.** It is exported in a user
  data request and anonymised together with the uploader attribution in the
  per-user, multi-user and context-wide privacy deletions. (Codex review.)

## [0.15.0-beta] — 2026-07-15

Build `2026071500`.

### Added
- **Replacement image optimization.** The Replace panel can now resize and
  re-encode uploaded replacements before they are applied: a longest-edge
  pixel cap (default 1920) plus a JPEG/WebP quality (default 85). The
  optimization runs as its own paced background phase (with live progress)
  before anything is written, each uploaded file is rewritten in place exactly
  once, GIF/SVG are never altered, and a re-encode is only kept when it makes
  the file smaller.
- **Chunked replacement uploads.** The replacement ZIP picker is now a
  filemanager accepting up to 50 archives, so a replacement set larger than
  the site's upload limit can be uploaded as several smaller ZIP chunks (e.g.
  re-uploading the extract's volumes one by one after watermarking). All
  chunks unpack into one pool and are matched to targets by filename.
- **Activity (module) search criteria.** Jobs can now scope files to an
  activity type (Lesson, Page, ...) and an instance-name pattern such as
  "Lesson 1*", matching files stored in those activities' contexts. The type
  is validated against installed modules before ever touching SQL.
- **Per-module and image metadata in the export.** The manifest CSV and the
  per-image JSON sidecars now carry the activity the file came from (`cmid`,
  `module`, `modulename`) plus the file's `author`, `license`, `imagewidth`
  and `imageheight`. Author/licence are captured at match time; dimensions are
  resolved as each file is packed.
- **Metadata-only replace mode.** A third replace mode updates the author
  and/or licence of every matched file without touching image content - no
  upload, no backups needed, old values recorded per item, with its own
  confirmation screen and preview of current values.

### Changed
- **Content replaces now preserve the target's metadata.** Replacing a file's
  content keeps the original author, licence, uploader and creation time on
  the new file instead of adopting the uploaded replacement's, so credit
  lines and date-based criteria keep working after a replace.

## [0.14.0-beta] — 2026-07-14

Build `2026071400`.

### Added
- **Live progress bar for the analysis phase.** Analysing a large course used
  to be a black box: the job sat on "Analysing…" with no feedback until the
  whole scan finished, because the matched totals were only written at the end.
  The analyse task now records an upfront estimate of the matched files (one
  COUNT over the criteria when the scan starts) and advances a counter after
  every throttled batch, and both the job page and the jobs overview render a
  real progress bar ("scanned X of ~Y") while the scan is still running. The
  same live bar covers the "removing previous results" stage of a re-analysis
  and the background clearing state, and each match batch now also logs its
  progress to the task log. New `progressstage` / `progressdone` /
  `progresstotal` fields on the job table carry the report (DB upgrade).
- **The job page auto-refreshes while a job is running or clearing** (every
  20 seconds), so progress advances without hammering reload.

## [0.13.4-beta] — 2026-07-13

Build `2026071004`.

### Fixed
- **Submitting the Replace panel hung the request forever (and with it, via the
  session lock, every other page for that user).** `replace_form::validation()`
  checked the upload with `moodleform::get_new_filename()`, but that method
  calls `is_validated()`, which runs `validation()` again — infinite recursion.
  The request span on CPU until the worker was killed; with *Debug messages*
  set to DEVELOPER the burn was amplified because every draft-area query in the
  loop ran `debug_backtrace()` over a stack thousands of frames deep. Diagnosed
  from a php-fpm slow-log stack trace on a live site. The job form's CSV check
  had the same latent recursion (since the first release) whenever a CSV mode
  was chosen. Both validations now inspect the submitted draft area directly
  via a new recursion-safe `manager::draft_has_file()`, and mocked-submission
  tests exercise the real validation so any regression hangs CI instead of
  passing.

## [0.13.3-beta] — 2026-07-13

Build `2026071003`.

### Fixed
- **Deleting a job with a large analysis could time out the web request.**
  Delete removed every prepared item row (and any per-item backups) in one
  synchronous pass — the last remaining unbounded operation on a web request.
  It now works like "Clear results": the bounded pieces (downloads, CSV,
  replacement source) are removed immediately, the job is parked as *Clearing*,
  and the existing chunked, throttled background task removes the item rows
  across cron runs and then deletes the job definition. A job with nothing
  heavy to remove is still deleted inline.

## [0.13.2-beta] — 2026-07-13

Build `2026071002`.

### Fixed
- **The results and replace-confirmation pages could time out on a large job,
  so the extract/replace action never queued.** `review_summary()` counted the
  `pending` and `skipped` item rows on every load, but immediately after analyse
  those millions of rows are freshly inserted and unvacuumed, so PostgreSQL
  cannot answer the count from the index alone and scans the heap for tens of
  seconds — long enough to hit the gateway timeout before the task was queued.
  The counts were not used by either page (both show the stored `totalmatched`
  and a 50-row sample), so they are gone: the review now reads only the bounded
  sample. The sample query also drops its `ORDER BY id`, so it can never sort
  the whole jobid partition before applying the `LIMIT`.

## [0.13.1-beta] — 2026-07-13

Build `2026071001`.

Follow-up fixes addressing review of the unified extract/replace flow.

### Fixed
- A **"broken or missing files"** analysis can no longer be extracted: its
  files are selected because their content is unreadable, so packing them only
  re-fails. The results page hides the Extract panel and explains that such a
  selection can be replaced or restored, not extracted.
- **Per-row-criteria CSV jobs cannot be replaced** (they stay extract-only, as
  before the unified flow): the Replace panel is hidden, and the ban is enforced
  in the queue path itself so it also holds for the CLI, not just the page.
- The **ZIP replace confirmation** no longer implies every matched file will be
  replaced; it now notes that targets whose filename has no matching entry in
  the uploaded ZIP are skipped at apply time. Single-image replacements still
  replace every target.
- The Extract panel is now **seeded from an extract job's stored naming rule and
  volume size**, so it shows its own options rather than the site defaults; a
  not-yet-actioned criteria-only job still shows the configured defaults instead
  of overwriting them with the schema default volume size.
- An existing replace job with a **stored replacement source** for its chosen
  mode reuses it instead of demanding a fresh upload; new jobs still require one,
  and reusing the source no longer wipes the stored image with the empty upload.
- The **direct (CLI) extract path** no longer de-duplicates — normalised for
  every direct extract run, including existing/upgraded extract jobs — matching
  the web analyse → extract path.
- JSON sidecars and the manifest again record the real **course id and short
  name** for each analysed extract (the type-agnostic match records neither, so
  the course is now resolved when the file is packed).

## [0.13.0-beta] — 2026-07-13

Build `2026071000`.

### Changed
- **One unified flow: select → analyse → results → extract or replace.** The
  extract-versus-replace choice is no longer made up front. A job is now
  created **criteria-only** (name, description, file-selection criteria, CSV
  mode and the "only broken or missing files" refinement). You press
  **Analyse** to run the existing paged, throttled matcher, which records the
  matched files as **type-agnostic** item rows (no replacement is resolved and
  no output name is computed at match time). The job then shows **Results
  ready**, and the job page presents the matched count and size, a sample and
  thumbnails of the matched originals, and two action panels:
  - **Extract** — a naming rule and volume size, then *Download / Extract*.
    Choosing it packs the **already-matched** items into ZIP volumes, computing
    each output name at pack time; the packing task no longer re-matches.
  - **Replace** — shown only to a site administrator with the replace feature
    enabled (enforced server-side): the replacement source (single image or ZIP)
    and a "back up originals" option. Choosing it stores the source and shows
    the existing old-vs-new thumbnail comparison and final "are you sure"
    confirmation before the destructive apply is queued. Replacements are
    resolved (and targets with no match **skipped**) at apply time.
- The job form is now **criteria-only**: the type selector, the output/naming
  and replacement sections and their `tool_imageextractor/jobtype` toggling are
  gone. A new job's `jobtype` is stored empty until an action is chosen.
- New form classes `extract_form` and `replace_form` back the two results-page
  panels; the previous `job_form` replace/output sections are removed.
- A new job stores an empty `jobtype` (set explicitly when it is created) until
  an action is chosen; the column keeps its schema default and existing jobs
  keep their stored type and continue to work.

### Removed
- **The live "Estimate matches" web service and its form field are gone.** The
  synchronous all-files count could time out on large sites, and the analyse
  pass now reports exact totals instead. Deleted the `estimate_matches`
  external function and its `db/services.php` entry, the
  `tool_imageextractor/estimate` and `tool_imageextractor/jobtype` AMD modules
  (and built artifacts), and the now-unused language strings.

### Note
- **Per-content-hash de-duplication is temporarily not applied.** The old
  extract collapsed duplicate content hashes during its hash-ordered prepare,
  but the unified, type-agnostic match cannot do that, so for now every matched
  item is packed. Restoring de-duplication (at pack time) is a planned
  follow-up. The `dedupe` UI control and handling have been removed.

## [0.12.0-beta] — 2026-07-10

Build `2026070907`.

### Fixed
- **A replace job's *matching* and *clearing* phases could still make the whole
  site unresponsive.** The earlier throttle paced the apply batches, but a
  replace job's analyse phase matched the entire file table and inserted every
  item row in a single background run, and clearing a previous run deleted
  millions of rows in one `DELETE` — either one pinned a small/shared database
  for its whole duration, so every page (including saving a job) stalled while
  a job ran. Both phases are now paced like the rest:
  - **Matching** pages through the file table with a keyset cursor — one
    bounded batch of targets per cron run, resumable, with the throttle delay
    between batches. The paging is idempotent (a retried batch cannot duplicate
    targets), and the running match count climbs as it goes.
  - **Clearing** (on re-run, on "Clear results", and on editing a job) deletes
    item rows and their backups a bounded chunk at a time across paced runs
    instead of one giant delete.

### Added
- Index `(jobid, fileid)` on the item table so the paged matcher's per-batch
  idempotency check stays cheap on a job with millions of rows.
- Unit coverage for paged/idempotent matching and chunked clearing.

### Safety
- The paged match is **retry-safe**: a replayed batch (after a crashed worker)
  removes and re-records only its own file ids — never rows a later page wrote
  — and the matched totals are recomputed from the recorded rows at the end
  rather than incremented per batch, so a replay can neither drop nor inflate
  them.
- A job can no longer be **edited while it is running or clearing**, so a paged
  match can't record some pages with the old criteria and later pages with an
  edited definition.

### Note
- Extract (download) jobs already cap each packing run by batch size; their
  *matching* phase is not yet paged (duplicate-collapsing across pages needs
  more care) and remains a single pass — a follow-up. Replace jobs, the case
  that prompted this, are fully paced.

## [0.11.0-beta] — 2026-07-09

Build `2026070906`.

### Added
- **Thumbnail preview on the replace review screen.** The "Awaiting review"
  page now shows the first few matched targets with the **current image and
  the replacement side by side**, so the outcome is obvious before you confirm
  a destructive apply. The replacement images are served inline (admin-only,
  via the plugin's file handler); a non-image target or a target with no
  matching replacement shows a small "No preview" placeholder instead.

## [0.10.0-beta] — 2026-07-09

Build `2026070905`.

### Fixed
- **A running job could overload the database and make the whole site
  unresponsive.** Re-queued background batches ran back-to-back within a single
  cron run, so a large replace/extract job hammered the database continuously
  (one or more `mdl_files` queries per file). On a small or shared server that
  saturated the database and every page — including saving a job — stalled
  behind it. Background tasks are now **throttled**: each batch processes a
  bounded number of files, then re-queues the next batch a short time in the
  future so the database is left idle between bursts and ordinary requests stay
  responsive. Large jobs take longer but no longer take the site down.

  Extract jobs are capped by batch size too: each run packs at most one
  batch of files into a volume (so a volume holds at most that many files and
  spills into the next paced run), rather than fetching enough files to fill a
  multi-gigabyte volume in a single burst.

### Added
- Two settings to tune the throttle for your hardware: **Batch size** (files
  per batch — and, for extract jobs, the maximum files per ZIP volume; default
  50) and **Throttle delay** (seconds between batches, default 20; set to 0 to
  process as fast as possible). Unit coverage for both.

## [0.9.0-beta] — 2026-07-09

Build `2026070904`.

### Fixed
- **Timeout when running, re-running, editing or clearing a large replace
  job.** Even after the earlier set-based backup delete, removing a previous
  run's item rows still happened *inside the web request* — pressing Run,
  Analyse, "Clear results", or saving an edited job could delete millions of
  rows synchronously and blow past the gateway limit (504). None of these
  paths delete synchronously any more:
  - **Run / Analyse** now only queue the background task and return
    immediately; the task clears the prior run's items and backups as its
    first step, on the next cron, with no time limit. (A reviewed replace job
    still applies its already-prepared targets without clearing them.)
  - **Clear results** and **editing a job** hand a large result set to a new
    background `reset_job` task; the job shows a **Clearing** state until it
    returns to draft. The bounded, downloadable outputs (ZIP volumes, manifest
    and counters) are still removed *synchronously* so the job page stops
    serving the previous run's downloads the instant it is cleared or edited —
    only the heavy item-row and per-item backup delete is deferred. A job with
    nothing heavy to remove is still cleared inline.

### Added
- A **Clearing** job state (shown on the overview and job page) for the window
  while a background reset runs, and unit coverage for the deferred-clear path.

## [0.8.0-beta] — 2026-07-09

Build `2026070903`.

### Fixed
- **Timeout when re-running or clearing a large replace job.** Clearing a
  job's results looped over every analysed item (one query each) to remove
  backups — with millions of stale item rows, pressing Run or "Clear results"
  stalled past the gateway limit. Backups are now removed in one set-based
  pass whose cost scales with the backup files that actually exist.

### Changed
- **A CSV match list now nominates exact files.** When "Select files using"
  is a match list, the criteria fields (courses, categories, MIME types,
  component, pattern, sizes, dates and "Images only") are hidden and ignored
  on save, so a stale filter can never silently exclude a nominated file.
  Scope and per-row CSVs still combine with the criteria as before.
- The file-selection choice now leads the criteria section (the separate
  "CSV upload" section is gone), and choosing any CSV mode requires an
  uploaded CSV.
- The live/manual estimate hides (and stops querying) while a match list is
  selected — it reflects only the criteria fields.

### Added
- **Progress on the jobs overview**: a progress bar with counts and percent
  per job (matching the job page, which gains the same bar), and an
  "Analysing…" indicator while a replace job's background analysis runs.
- Unit coverage for match-list nomination ignoring criteria; Behat coverage
  for the match-list selection hiding the criteria fields.

## [0.7.1-beta] — 2026-07-09

Build `2026070902`.

### Fixed
- **Database load while analysing a replace job.** The background analyse
  (and the CLI dry-run preview) inherited two expensive habits from the
  extract path:
  - the matched-files query sorted the **entire** matched set by content hash
    — an ordering only extract's duplicate-collapsing needs — forcing the
    database to materialise and sort millions of rows (spilling to temporary
    disk) before the first row streamed; the replace paths now read unordered;
  - every matched row was hydrated into a `stored_file` (one extra query per
    row, millions of matches → millions of queries) just to re-check
    conditions the SQL already guarantees; the lookup now happens only in
    "broken/missing only" mode, which genuinely needs to inspect content.
  Together the analyse pass drops from `2n+1` queries plus a full sort to a
  single streaming query (plus batched inserts).

## [0.7.0-beta] — 2026-07-09

Build `2026070901`.

### Changed
- **One job form that adapts to the job type.** The separate extract and
  replace forms are merged into a single form with a type selector at the top
  — *Extract (download images as ZIP archives)* or *Replace (upload
  replacement content)*. Only the sections relevant to the chosen type are
  shown: the output/naming/volume section and match estimate for extract, the
  replacement-source and backup section (and "broken/missing only" filter)
  for replace. Section visibility switches live as the type is changed (a new
  `tool_imageextractor/jobtype` AMD module hides whole sections; per-field
  `hideIf` rules back it up). A job's type is fixed once saved.
- The replace type is offered only to site administrators with
  "Allow replace/restore" enabled, and the same rule is enforced server-side
  on save (previously creating — though not running — a replace job was
  possible for any user with the manage capability).
- Per-row criteria CSVs are now rejected for replace jobs with a clear
  validation message (previously the option simply wasn't listed).
- `replace_form.php` removed; `job_form` handles both types.

### Added
- Behat coverage for the type selector showing/hiding the relevant sections.

## [0.6.0-beta] — 2026-07-09

Build `2026070900`.

### Fixed
- **Gateway timeout (504) when running a replace job.** The pre-run preview
  used to scan (and sort) the entire matched file set inside the web request,
  which exceeded the proxy/gateway time limit on large sites.

### Changed
- **Replace jobs now run in two phases.** Pressing Run queues a background
  **analyse** pass that matches the targets and resolves their replacements
  without changing anything. The job then waits in a new **Awaiting review**
  state, where the job page shows an *exact* preview (counts and a sample,
  read from the prepared targets — no file-table scan) with the final
  confirmation. Confirming queues the destructive apply phase; the web flow
  can no longer reach apply without a completed analysis. "Clear results"
  discards an analysis.
- The extract Run confirmation no longer computes a synchronous match count
  (same timeout class); use the edit form's estimate instead — exact totals
  appear on the job page as the run progresses.
- The CLI is unchanged (`--execute --confirm` still applies in one shot,
  preparing inside the task); a dry run on an analysed job now reports the
  stored breakdown instead of re-scanning.
- Clearing results now also resets the stored matched totals, and a retried
  analyse cannot duplicate prepared targets.

### Added
- PHPUnit coverage for the analyse → review → apply state machine, the CLI
  one-shot path, and discarding an analysis.

## [0.5.1-beta] — 2026-07-09

Build `2026070104`.

### Added
- **CSV scope lists can name course categories**, not just courses and users.
  A `category` / `categoryid` column (or `categoryidnumber`) is resolved by id,
  idnumber or name, in both scope and per-row CSV modes; unresolved values are
  reported as warnings and skipped.
- **Behat acceptance tests** covering creating an extract job, the server-side
  Estimate button, the live (AJAX) estimate updating as criteria change, and
  scoping a job to a course category.
- Unit coverage for CSV category resolution (`csv_importer_test`).

### Changed
- Added a help tooltip to the live-estimate field explaining it is approximate
  and ignores CSV refinement; the scope-CSV help now documents categories.
- The match estimate (button and live region) now sits within the criteria
  section, so the live figure stays visible without expanding a collapsed
  fieldset.

## [0.5.0-beta] — 2026-07-01

Build `2026070103`.

### Added
- **Live estimate** on the extract form: as you change the criteria (course,
  category, component, MIME, size, dates…), an inline region updates with an
  approximate match count and total size — no button press or page reload. This
  is progressive enhancement over the existing **Estimate matches** button,
  which remains as a no-JavaScript fallback.
- New read-only web service `tool_imageextractor_estimate_matches`
  (`classes/external/estimate_matches.php`, `db/services.php`) that the AMD
  module `tool_imageextractor/estimate` calls; it reuses `criteria_from_data`
  and the matcher, and requires the `tool/imageextractor:manage` capability.
- Test coverage for the web service (counts, course scope, capability check).

## [0.4.2-beta] — 2026-07-01

Build `2026070102`.

### Added
- **Estimate button** on the extract form: recomputes the approximate match
  count and total size from the current criteria (course, category, component,
  MIME, size, date, etc.) without saving the job. The estimate reflects the
  criteria fields only and ignores CSV refinement.

### Changed
- Extracted the form-to-criteria mapping into a reusable
  `manager::criteria_from_data()` (shared by `save_job` and the new estimate),
  with id-list cleaning centralised in a helper. Behaviour is unchanged; the
  mapping is now unit-tested directly.

## [0.4.1-beta] — 2026-07-01

Build `2026070101`.

### Added
- **Category scope**: the extract and replace forms now include a course
  category picker. Selecting a category includes every course beneath it —
  including nested subcategories — resolved by context path. Category scope is
  combined (union) with the course picker, so a file matches when it is in any
  selected course *or* category.
- Test coverage for category scoping (`matcher_test`) and for how the form's
  course/category selections are folded into a job's stored criteria
  (`manager_test`).

## [0.4.0-beta] — 2026-07-01

Build `2026070100`.

### Added
- **Course scope in the UI**: the extract and replace forms now include a
  course autocomplete so a search can be limited to one or more specific
  courses without needing a CSV. A file counts as belonging to a course when
  its context is the course context or any context nested beneath it
  (activities, blocks). Courses picked in the form are combined (union) with
  any courses supplied by a scope-list CSV. (The matcher already supported
  course scoping; this exposes it directly.)

### Changed
- Promoted plugin maturity from **alpha** to **beta** (`MATURITY_BETA`). The
  extract, replace and restore features are feature-complete and covered by the
  CI matrix (PHP 8.2–8.4 × Moodle 5.0–5.2 × PostgreSQL/MariaDB); the plugin is
  now considered suitable for wider testing on non-production sites.
- Added this changelog.

## [0.3.0] — 2026-06-28

Build `2026062800`.

### Added
- **CLI job runner** (`cli/run_job.php`): list jobs, dry-run an estimate
  (extract) or preview (replace), or drive a job to completion inline by
  draining its adhoc tasks — useful for very large jobs where waiting on cron
  is awkward.
- **Destructive replace preview** in the web UI: running a replace job shows a
  strong warning, a read-only sample of the files that would be overwritten and
  their resolved replacements, and a final confirmation before anything is
  applied. Backed by a new read-only `replacer::preview()`.

### Changed
- **Replace and restore are now restricted to site administrators**
  (`is_siteadmin()`); the manage capability alone is no longer sufficient.
- CLI replace jobs require **both** `--execute` and `--confirm`.
- README documents the CLI usage and the admin-only/preview behaviour.

## [0.2.1] — 2026-06-28

Build `2026062702`. Initial release.

### Added
- **Extract**: search the Moodle file storage by component, file area, MIME
  type, filename pattern, size range and creation date; optional CSV upload as a
  scope list, match list or per-row criteria; naming-rule templates with
  placeholders; optional de-duplication; output split into capped-size ZIP
  volumes with a master manifest CSV and per-image JSON sidecars.
- **Replace / restore** (off by default, opt-in setting): reuses the matcher to
  select targets, with a "broken/missing only" filter; single-image or
  ZIP-of-replacements strategies; backs up every original so a job can be
  restored (undone); replaces content at the same logical location so embedded
  references and pluginfile URLs keep working.
- **Scale & safety**: master kill-switch plus a separate opt-in for the
  destructive replace feature; all heavy work runs as throttled, resumable
  adhoc tasks with per-task concurrency caps; scheduled cleanup task; privacy
  provider.
- Unit tests for the matcher, naming renderer, CSV importer and replace/restore
  engine.
- GitHub Actions CI matrix across PHP 8.2–8.4, Moodle 5.0–5.2, PostgreSQL and
  MariaDB.

[0.13.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.12.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.11.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.10.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.9.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.8.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.7.1-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.7.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.6.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.5.1-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.5.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.4.2-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.4.1-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.4.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.3.0]: https://github.com/verzog/moodle-tool_imageextractor
[0.2.1]: https://github.com/verzog/moodle-tool_imageextractor
