# Changelog — block_catquiz_statistics

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [0.5.0] — Build 2026093004 (30.09.2026)

### Added (Build 2026093004) — Issue #9, synthetische Demo-Kohorte
- `demo\rng` (mulberry32, eigener Zustand), `demo\scenario` (Profile balanced | highuptake_lowlearning |
  lowuptake_highperformance | mixed; SIMULIERTE Parameter), `demo\cohort_generator` (generate/simulate/reset).
- Tabellen `demo` und `demouser` (Registry): Reset entfernt ausschließlich, was ein Lauf erzeugt hat,
  und bricht ab, wenn der registrierte Kurs kein Demo-Kurs ist.
- Ebene A: Demografie, MMQ-ähnliche Items mit Konstrukten (inkl. Reverse-Item), CAT-Ergebnisse T0/T1 mit SE,
  Meilensteine (Test gestartet/abgeschlossen, Feedback geöffnet, Empfehlung ausgegeben, Lernangebote
  geöffnet/abgeschlossen, Re-Test), Demo-Evaluationsmodell entlang der Wirkungskette.
- Ebene B: eigener Demo-Kurs („SYNTHETIC DEMO DATA“), nicht anmeldbare Demo-Nutzer ohne reale Kennungen
  (example.invalid, keine idnumber), echtes Gradebook-Item „Exam“ mit Outcome-Definitionen (Punkte, bestanden).
- Alle Datenpunkte sind `issynthetic`; Konstruktwerte übernehmen die Markierung, Outcomes aus Demo-Kursen werden
  vom Outcome-Provider als synthetisch gekennzeichnet.
- CLI `cli/demo.php` (--generate --seed --size --profile --classes | --list | --reset=ID), nur mit Setting `enabledemo`.

### Fixed (Build 2026093004)
- `outcome_provider` lud die Grade-Konstanten nicht (GRADE_TYPE_SCALE) — trat nur außerhalb der Tests auf.

### Build 2026093003 (30.09.2026)

### Added (Build 2026093003) — Issue #6, Outcome-Adapter
- Tabelle `outcome` (nur Definitionen; Werte werden live gelesen, keine Replikation von Gradebook-Daten).
- `outcome_repository`: Quelle Gradebook-Item (Signale grade | passfail | gradepresent) oder Aktivität
  (attempt: quiz/adaptivequiz · submission: assign · completion · grade); Wertmodus value | indicator | date
  (Zeitpunkt als Datum = time-to-event); raw/final grade; pass/fail nur mit expliziter Regel
  (Schwelle oder Bestehensgrenze des Items); Abwesenheits-Semantik norecord (Standard) | notparticipated | unknown | false.
- `outcome_provider` (Variablenschlüssel `outcome:<id>`): Population = Abfrage-Nutzer oder bewertbare Nutzer des
  Quellkurses; Zeile ohne Note → missing_notgraded; ausgeschlossene Note → not_applicable; Provenienz des Quellkurses
  bleibt auch bei kursübergreifenden Outcomes erhalten.
- `data_quality::for_outcome()`: N, Missing nach Grund, Zeit-/Wertebereich, Kategorien, grademin/grademax/gradepass.
- Selektortypen `outcome` und `construct` im Evaluationsmodell.
- Externe Outcomes über den bestehenden CSV-Import (inkl. Messzeitpunkt und Datumsspalte).

### Build 2026093002 (30.09.2026)

### Added (Build 2026093002) — Issue #3, Befragungsquellen
- `import\survey\survey_source_interface` mit Adaptern `questionnaire_source` (mod_questionnaire) und
  `feedback_source` (mod_feedback); `import\survey_importer`.
- Questionnaire: Rate-Fragen → ein ordinales Item je Zeile (1-basiert bzw. benannte Stufen; N/A = -1 → Missing),
  Radio/Dropdown → kategorial, Ja/Nein → boolesch, Zahl, Text, Datum; nur vollständige Antworten;
  gelöschte Fragen werden übersprungen (alte 'y'/'n'- und neue Zeitstempel-Kennzeichnung).
- Feedback: bewertete Mehrfachauswahl → Gewicht der gewählten Option, Einfachauswahl → Optionstext,
  Zahl, Text; „nicht ausgewählt“ → Missing; Layout- und Mehrfachantwort-Items werden nicht importiert.
- Anonyme Befragungen werden grundsätzlich nicht personenbezogen importiert.
- Re-Import: identische Antworten → bestehender Datensatz; geänderte Antworten → neue Version, die die alte ablöst;
  je Person zählt die letzte vollständige Abgabe; optionale Zuordnung zu bestehenden Registervariablen (`variablemap`).
- CI: mod_questionnaire (MOODLE_404_STABLE) in der 4.5-Linie.

### Build 2026093001 (30.09.2026)

### Added (Build 2026093001) — Issue #3, Service-Schicht
- Konstrukteregister: Tabellen `construct` (Instrument, Subskala via `parentid`, Aggregation mean | sum (prorated) |
  weightedmean, Mindestanzahl gültiger Items, Version) und `citem` (Item, Reverse-Coding, Gewicht).
- CSV-Import (`import\csv_table`, `import\value_parser`, `import\csv_importer`): Trennzeichen-/Encoding-/BOM-Erkennung,
  Dezimalkomma, Typ-/Messniveau-Vorschlag (überschreibbar), Preview mit matched/unmatched/ambiguous,
  Export unaufgelöster Zeilen, Mehrfachtreffer stoppen den Import, Missing-Codes (global/je Variable),
  ungültige Werte bleiben als INVALID erhalten, Duplikatzeilen, idempotent über Import-Hash,
  Korrektur-/Versionsimporte (überholte Versionen werden in Abfragen ausgeblendet).
- Konstrukt-Scoring (`import\construct_scorer`) mit vollständiger Provenienz; Datenqualität (`import\data_quality`).
- Status `missing_coded`, `missing_insufficient`, `invalid`; Variablenschlüssel `construct:<id>`.

### Build 2026093000 (30.09.2026)

### Fixed (Build 2026093000)
- Upgrade von 0.4.x brach mit `ddldependencyerror` ab: Schritt 2026092900 legt die Tabellen aus der
  aktuellen install.xml an (bereits mit `occasion` und neuem Index), Schritt 2026092902 änderte danach
  Spaltenlängen unter einem bestehenden Index. Schritt 2026092902 entfernt nun beide möglichen Indizes
  vor der Längenänderung und ist re-entrant (auch für Sites, die im fehlgeschlagenen Zustand hängen).

### Build 2026092902 (29.09.2026)

### Added (Build 2026092902)
- Messanlass `occasion` in der Rollenzuordnung (any | first | last | attempt:N | tp:<label> | window:<from>-<to>);
  Unique-Index über Modell + Selektor + Anlass. Wertobjekt `analytics\occasion` mit Auswahlsemantik.
- Semantische Event-Schicht (Issue #4): `semantic_adapter_interface`, `milestone_spec`, `adapter_registry`,
  Hook `collect_semantic_adapters`, `observer`, `db/events.php`; Standardadapter für
  `attempt_completed` (nur Signal), `result_page_viewed` (mod_adaptivequiz#15) und `feedbacktab_clicked`.
- `semantic_label`: wörtliche UI-Bezeichnungen ohne Überinterpretation.
- Advanced-Modus: Tabelle `eventmap`, `eventmap_repository`, geplante Aufgabe
  `materialise_custom_milestones` (Logstore, inkrementell mit Watermark).
- Settings `disabledadapters`, `enableadvancedmapping`.

### Changed (Build 2026092902)
- `milestone_repository::merge()` als gemeinsame Operation für Live-Observer und Log-Materialisierung.
- `evalrole`: `selectortype` 20, `selector` 160 Zeichen (Indexlimit 333 Zeichen).

### Build 2026092901

### Added
- Longitudinales Datenfundament (Issue #2): sieben eigene Tabellen
  (`dataset`, `variable`, `observation`, `milestone`, `subjectmap`, `evalmodel`, `evalrole`)
  inkl. `db/upgrade.php`. Datenpunkte gehören nie einer Blockinstanz.
- Analytische Kerntypen: `analytic_role`, `semantic_action`, `object_type`, `value_type`,
  `observation_status` (explizite Fehlwert-Semantik), `observation` (DTO), `observation_query`.
- Provider-/Service-Grenze: `observation_provider_interface`, `catquiz_provider`
  (liest CATquiz-Versuche, kopiert nichts), `milestone_provider`, `imported_observation_provider`,
  `analytics_query_service` (kursübergreifende, chronologische Timeline).
- Hook `collect_observation_providers` als Erweiterungspunkt für spätere Consumer/Adapter.
- Identity-Resolution (`subject_resolver`): exakt, ohne Fuzzy-Matching, mit Mehrdeutigkeits-
  und Nicht-Treffer-Ausweis und auditierbarer Speicherung.
- Evaluationsmodell-Repository: Rollenzuordnung modellbezogen, versioniert.
- `attempt_filter::$userids`, `attempt_repository::get_module_contextids()`.
- Fünf Learning-Analytics-Capabilities: `viewanalytics`, `configuremodel`, `importdata`,
  `viewanalyses`, `managedemo` (+ Setting `enabledemo`), Guard-Methoden in `access`.

### Changed
- Privacy-Provider auf `plugin\provider` + `core_userlist_provider` erweitert (Export/Löschung).
- CI: Dependencies versionsabhängig auf die verbindlichen Zielstände gepinnt
  (4.5: `ALiSe-v-1.2.0-legacy` / adaptivequiz `master`; 5.x: `migration-zu-moodle-5.x` / `v-3.0`);
  Moodle-5.0-Matrixzeilen durch 5.1 ersetzt. `catquizcentralhub` wird nicht geladen;
  leere Submodule-Verzeichnisse von local_catquiz werden entfernt (sonst PHP-Warnungen).
- Test-Generator vergibt eindeutige `attemptid` (UNIQUE-Index in aktuellem local_catquiz).

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
