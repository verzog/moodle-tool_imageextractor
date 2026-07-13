# Changelog

All notable changes to **tool_imageextractor** are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and the project uses date-based Moodle build numbers (`$plugin->version`)
alongside a human-readable `$plugin->release` string.

## [0.13.1-beta] — 2026-07-13

Build `2026071001`.

Follow-up fixes addressing review of the unified extract/replace flow.

### Fixed
- **The results and replace-confirmation pages could time out on a large job,
  so the extract/replace action never queued.** `review_summary()` counted the
  `pending` and `skipped` item rows on every load, but immediately after analyse
  those millions of rows are freshly inserted and unvacuumed, so PostgreSQL
  cannot answer the count from the index alone and scans the heap for tens of
  seconds — long enough to hit the gateway timeout before the task was queued.
  The counts were not used by either page (both show the stored `totalmatched`
  and a 50-row sample), so they are gone: the review now reads only the bounded
  sample, with no aggregate over the item table.
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
