# Changelog

All notable changes to **tool_imageextractor** are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and the project uses date-based Moodle build numbers (`$plugin->version`)
alongside a human-readable `$plugin->release` string.

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
