# Changelog — block_catquiz_statistics

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Added
- Initial plugin stub: installs, upgrades, uninstalls cleanly on Moodle 4.5.
- `lib.php` navigation callback: adds a link to the course "Berichte" (Reports) tab
  when the block is present or an adaptive quiz instance exists in the course.
- Empty course-level report page (`report.php`, requires `viewdetails`).
- Empty system-wide admin report page (`adminreport.php`, requires `viewall`).
- Seven-capability access model: `addinstance`, `myaddinstance`, `view`,
  `viewdetails`, `viewdebug`, `export`, `viewall`.
- Dual-capability enforcement: personal-data capabilities additionally require
  `local/catquiz:view_users_feedback`; `viewall` requires `local/catquiz:canmanage`.
- Privacy provider (`metadata\provider`) — documents five external tables read
  by the plugin; no personal data stored by the plugin itself (Phase 1 / MVP).
- Repository isolation layer (`attempt_repository`) with schema compatibility
  check, JSON parsing helpers, and stub method signatures for Phases 1–3.
- Immutable filter value object (`attempt_filter`) with `from_request()` factory.
- `attempt_data` DTO covering all structured columns, parsed JSON fields,
  `graphicalsummary_data`, and computed fields.
- Report interface (`report_interface`) and Test Results stub (`attempt_results_report`).
- Export base class and factory; Test Results exporter stub; adhoc task skeleton.
- Response normaliser stub (qtype-aware, order-independent — Phase 2 TODOs).
- Mustache templates: `block_main`, `report_page`.
- English and German language strings for all capabilities, settings, and modules.
- Admin settings: `defaultformat`, `maxsheets`, `enableqejoin`, `enablemoduled`.
- PHPUnit tests for repository filter logic and exporter factory (11 tests).
- Behat scenarios for block visibility and report access control.
- Makefile covering lint, auto-fix, AMD rebuild, and PHPUnit.
- GitHub Actions CI: `moodle-ci.yml` (dev branches, 4 jobs + gate) and
  `moodle-release.yml` (main branch, full matrix with `--fail-on-warning`).

### Not yet implemented (planned)
- Test Results data: attempt rows, aggregate stats, SE validity check.
- Multi-sheet XLSX/ODS writer (8 named sheets).
- Test Usage: Test Usage / Reliable Change Index.
- Test Progress: Test Progress (graphicalsummary_data + QE join).
- Learning Activity: Learning Activity log archival (opt-in, privacy review required).
- Item & Response Analysis: Item & Response Analysis (QE join, distractor frequency table).
- Response normaliser adapters for multichoice, match, ddwtos, cloze.
- Ad-hoc task execution (large/system-wide exports).
- AMD JavaScript (interactive filters, chart rendering).
- `edit_form.php` for per-instance block configuration.
