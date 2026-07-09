# Changelog

All notable changes to **tool_imageextractor** are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and the project uses date-based Moodle build numbers (`$plugin->version`)
alongside a human-readable `$plugin->release` string.

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

[0.5.1-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.5.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.4.2-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.4.1-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.4.0-beta]: https://github.com/verzog/moodle-tool_imageextractor
[0.3.0]: https://github.com/verzog/moodle-tool_imageextractor
[0.2.1]: https://github.com/verzog/moodle-tool_imageextractor
