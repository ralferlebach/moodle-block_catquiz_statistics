# Changelog — block_catquizstatistics

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Added
- Initial plugin stub: installs, upgrades, uninstalls cleanly on Moodle 4.5.
- Block renders report link for editing teachers and managers (`view` capability).
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
- Report interface (`report_interface`) and Module a stub (`attempt_results_report`).
- Export base class and factory; Module a exporter stub; adhoc task skeleton.
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
- Module a data: attempt rows, aggregate stats, SE validity check.
- Multi-sheet XLSX/ODS writer (8 named sheets).
- Module b: Test Usage / Reliable Change Index.
- Module c: Test Progress (graphicalsummary_data + QE join).
- Module d: Learning Activity log archival (opt-in, privacy review required).
- Module e: Item & Response Analysis (QE join, distractor frequency table).
- Response normaliser adapters for multichoice, match, ddwtos, cloze.
- Ad-hoc task execution (large/system-wide exports).
- AMD JavaScript (interactive filters, chart rendering).
- `edit_form.php` for per-instance block configuration.
