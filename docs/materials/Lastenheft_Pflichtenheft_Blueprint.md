# block_catquiz_statistics — Lastenheft · Pflichtenheft · Blueprint

**Moodle Block-Plugin**
Autor: Ralf Erlebach  
Stand: Juni 2025  
Lizenz: GPL v3 or later

---

## 1 Projektrahmen

### 1.1 Ausgangslage
`local_catquiz` (Wunderbyte GmbH, v1.1.2, 2024120500) liefert CAT-adaptierte
Tests (`STRATEGY_INFERALLCATS = 1 … STRATEGY_PILOT = 6`).  Testergebnisse
werden in `local_catquiz_attempts` gespeichert; die reichhaltigen JSON-Blobs
(`attempts.json`, `debug_info`) bleiben für Lehrende ohne Programmierzugang
unzugänglich.

### 1.2 Ziel
Ein eigenständiges Moodle-Block-Plugin, das

- Lehrenden kursweise aggregierte und personenbezogene Statistiken,
- Admins systemweite Item- und Antwortanalysen und
- allen Berechtigten Multi-Format-Datenexporte bereitstellt.

---

## 2 Systemkontext

| Komponente | Version | Anmerkung |
|---|---|---|
| Moodle | ≥ 4.5 (2024100700) | Tested: 4.5, 5.0 |
| PHP | ≥ 8.1 | Readonly properties, named args |
| local_catquiz | 2024120500 | Pflicht-Dependency |
| mod_adaptivequiz | 2024031502 | Wunderbyte-Fork, branch catmodel_main |
| local_wunderbyte_table | 2024040200 | Pflicht-Dependency |
| block_catquiz_statistics | dieses Plugin | component: block_catquiz_statistics |

---

## 3 Capabilities (Rechtemodell)

### 3.1 Sieben Capabilities

| Capability | Kontext | Archetypen | Zusatz-Cap (strikt) |
|---|---|---|---|
| `addinstance` | CONTEXT_BLOCK | editingteacher, manager | — |
| `myaddinstance` | CONTEXT_SYSTEM | (leer) | — |
| `view` | CONTEXT_COURSE | teacher, editingteacher, manager | — |
| `viewdetails` | CONTEXT_COURSE | editingteacher, manager | `local/catquiz:view_users_feedback` |
| `viewdebug` | CONTEXT_COURSE | editingteacher, manager | `local/catquiz:view_users_feedback` |
| `export` | CONTEXT_COURSE | editingteacher, manager | `local/catquiz:view_users_feedback` |
| `viewall` | CONTEXT_SYSTEM | manager | `local/catquiz:canmanage` |

### 3.2 Bedeutung
- **view**: Zeigt den Block-Widget mit anonymen Aggregaten.  Keine personen­
  bezogenen Daten.
- **viewdetails**: Vollständiger Kursbericht mit pro-User-Daten (RISK_PERSONAL).
- **viewdebug**: Testverlauf aus `graphicalsummary_data` / `debug_info`.
- **export**: CSV / JSON / XLSX / ODS-Download mit personenbezogenen Daten.
- **viewall**: Systemweite Kurs-übergreifende Auswertung (adminreport.php).

---

## 4 Datenquellen und Datenbankschema

### 4.1 Kanonischer Join-Pfad (Schicht 1 — QE-Join)

```sql
local_catquiz_attempts.attemptid           -- = adaptivequiz_attempt.id
  JOIN adaptivequiz_attempt aa             -- .uniqueid → QE
  JOIN question_attempts qa
    ON qa.questionusageid = aa.uniqueid
  JOIN question_attempt_steps qas
    ON qas.questionattemptid = qa.id       -- fraction, timecreated
  JOIN question_attempt_step_data qasd
    ON qasd.attemptstepid = qas.id         -- name/value response pairs
```

### 4.2 `attempts.json` (Schicht 2 — IMMER vorhanden)

```json
{
  "catscaleid":       1,
  "testid":           1,
  "personabilities":  { "1": 0.5 },
  "se":               { "1": 0.3 },
  "primaryscale":     { "id": 1, "name": "TestScale" },
  "catscales":        { "1": { "name": "TestScale" } },
  "graphicalsummary_data": [
    {
      "id": 1,
      "questionname": "q001",
      "lastresponse": 1.0,
      "difficulty":   0.2,
      "questionscale": 1,
      "questionscale_name": "TestScale",
      "fisherinformation": 0.95,
      "personability_after": 0.6
    }
  ]
}
```

`graphicalsummary_data` ist für ALLE 6 Standard-Strategien vorhanden
(STRATEGY_PILOT = 6 löst keinen eigenen Strategietyp aus).

### 4.3 `debug_info`-Spalte (Schicht 3 — nur wenn store_debug_info=true)

Zusätzliche Felder pro Schritt: `timestamp`, `activescales`, `rightanswer`,
`responsesummary`, `questionattemptid`.  Default-Einstellung: deaktiviert.

### 4.4 IRT-Parameter
```sql
question.id = local_catquiz_items.componentid
           → local_catquiz_itemparams.itemid
```
Felder: `difficulty`, `discrimination`, `guessing`.

### 4.5 SE-Validität
- `filter_nminscale()`: N_items_in_scale ≥ catquiz_minquestionspersubscale
  (aus `local_catquiz_tests.json`)
- `filter_semax()`: actual_SE ≤ catquiz_standarderror_max
- Bei fehlenden Settings: SE ausgeben (permissiv)

---

## 5 Auswertungsfunktionen

### 5.1 Testergebnisse (Phase 1, MVP)

**Flat/Wide-Spalten:**
userid, username, firstname, lastname, email,
testid, attemptid, starttime, endtime, duration,
teststrategy, status, number_of_testitems_used,
global_scale, global_pp, global_se,
primary_scale, primary_pp, primary_se,
[pro Subskala:] scale_{id}_pp, scale_{id}_se, scale_{id}_n, scale_{id}_frac

**Deskriptivstatistik:** n, mean, median, SD, min, max, Q1, Q3

**XLSX/ODS Multi-Sheet (8 Blätter):**
`attempts_raw`, `attempts_wide`, `scale_summary`, `subscale_scores`,
`subscale_se`, `subscale_n`, `subscale_frac`, `metadata`

### 5.2 Testnutzung (Phase 2)

Reliable Change Index: Δability / √(SE_first² + SE_last²)

### 5.3 Testverlauf (Phase 2)

- Schicht 1: QE-Join (erfordert enableqejoin)
- Schicht 2: graphicalsummary_data (immer verfügbar)
- Schicht 3: debug_info (optional, store_debug_info=true)

### 5.4 Lernangebotsnutzung (Phase 3, opt-in)

Erfordert: enablemoduled=1, explizite Datenschutzgrundlage.
Eigene Tabellen → Privacy-Provider auf plugin\provider upgraden.

### 5.5 Item- und Antwortanalyse (Phase 3)

Antwortdarstellung: `responsesummary | fraction | N | json`
Sortierung: fraction DESC, N DESC
Response Normalizer: qtype-aware, für MC über `_order`-Key shuffle-unabhängig.

---

## 6 Export-Engine

| Format | Modus | Moodle dataformat key |
|---|---|---|
| CSV | single-sheet | `csv` |
| JSON | single-sheet | `json` |
| Excel (XLSX) | single + multi-sheet | `excel` |
| ODS | single + multi-sheet | `ods` |

Multi-Sheet-Export: Writer-Klasse direkt verwenden (nicht dataformat::download_data).

---

## 7 Admin-Einstellungen

| Key | Typ | Default | Beschreibung |
|---|---|---|---|
| `defaultformat` | select | `csv` | Standard-Exportformat |
| `maxsheets` | int | 50 | Max. Blätter im Workbook |
| `enableqejoin` | checkbox | 1 | QE-Join für Test Progress/e |
| `enablemoduled` | checkbox | 0 | Lernangebotsnutzung (opt-in) |

---

## 8 Architektur und Klassen-Hierarchie

```
block_catquiz_statistics/
│
├── block_catquiz_statistics.php          # Block-Klasse (global namespace)
├── report.php                           # Kurs-Bericht-Einstieg (viewdetails)
├── adminreport.php                      # Systemweiter Admin-Report (viewall)
├── settings.php                         # Admin-Einstellungen
│
├── classes/
│   ├── access.php                       # Capability Guard (statische Methoden)
│   ├── dto/attempt_data.php             # Immutable DTO (alle Felder)
│   ├── export/
│   │   ├── base_exporter.php            # Abstract, dataformat wrapper
│   │   ├── exporter_factory.php         # Modul-ID → Exporter
│   │   └── attempt_results_exporter.php # Testergebnisse, 8-Sheet override (Phase 1)
│   ├── local/response_normalizer.php    # Qtype-aware, shuffle-unabhängig
│   ├── output/main.php                  # Block-Widget Renderable
│   ├── output/report_page.php           # Report-Page Renderable
│   ├── privacy/provider.php             # metadata\provider (MVP)
│   ├── report/
│   │   ├── report_interface.php         # get_module_id, get_flat_rows, ...
│   │   └── attempt_results_report.php   # Testergebnisse
│   ├── repository/
│   │   ├── attempt_filter.php           # Immutable Filter-VO (PHP 8.1 readonly)
│   │   └── attempt_repository.php       # EINZIGE DB-Schicht
│   └── task/export_adhoc_task.php       # Adhoc-Task für große Exports
│
├── lib.php                              # Kurs-Navigations-Callback (Berichte-Reiter)
├── db/access.php                        # 7 Capabilities
├── lang/{en,de}/block_catquiz_statistics.php
├── templates/{block_main,report_page}.mustache
└── tests/
    ├── behat/{block_visibility,report_access}.feature
    ├── export/attempt_results_exporter_test.php
    ├── generator/lib.php
    └── repository/attempt_repository_test.php
```

---

## 9 CI-Matrix

| Branch | Workflow | PHP | Moodle | DB |
|---|---|---|---|---|
| non-main | moodle-ci.yml | 8.1/8.2/8.3 | 4.5 + 5.0 | mariadb + pgsql |
| main | moodle-release.yml | 8.1/8.2/8.3 | 4.5 + 5.0 | mariadb + pgsql |

Gate-Job `ci-complete` als Branch-Protection-Status-Check.

---

## 11 Kurs-Navigation: "Berichte"-Reiter

Der Callback `block_catquiz_statistics_extend_navigation_course()` in `lib.php`
fügt dem **"Berichte"-Knoten** (`coursereports`) der Kursnavigation automatisch
einen Link auf `report.php` hinzu.

**Anzeige-Bedingung:** Der Link erscheint, wenn

- der Block im Kurs platziert ist **oder**
- mindestens eine mod_adaptivequiz-Instanz im Kurs existiert.

So bleibt der Bericht auch dann erreichbar, wenn der Block temporär aus dem
Kurs entfernt wurde (z.B. für ein aufgeräumtes Layout).

**Sichtbarkeit:** Nur Nutzer mit `block/catquiz_statistics:view` und
ausreichenden catquiz-Rechten sehen den Eintrag.

**Fallback:** Ist kein `coursereports`-Knoten vorhanden (keine weiteren
Berichtsplugins installiert), bleibt der Link über den Block-Widget und
die direkte URL erreichbar.

## 10 Nicht umgesetzte Entwurfsentscheidungen (bewusst zurückgestellt)

| Thema | Entscheidung |
|---|---|
| `edit_form.php` | Keine per-Instanz-Konfiguration im Stub; `instance_allow_config()` = false |
| AMD JavaScript | Kein AMD im Stub; `lint-js` erkennt leeres `amd/src/` und überspringt |
| `adaptivequizcatmodel_catquiz` in Pflicht-Dependencies | Indirekte Dependency über local_catquiz; nicht in version.php |
| `local_moodlecheck` PHPDoc-Prüfung | Nur in `make check` / lint-js-Job; kein eigener CI-Job |
| Privacy `plugin\provider` | Phase 3 (Lernangebotsnutzung), wenn eigene Tabellen hinzukommen |
| `local/catquiz:view_users_feedback` für `view` | Abgelehnt: `view` zeigt nur Aggregate → keine personen­bezogenen Daten |
