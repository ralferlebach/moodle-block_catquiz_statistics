<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German language strings for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
$string['adminreport:comingsoon'] = 'Die systemweite Item- und Antwortanalyse steht in einer späteren Version zur Verfügung.';
$string['adminreporttitle'] = 'CAT-Quiz-Statistik – Systembericht';
$string['block:attempts'] = 'Versuche';
$string['block:instances'] = 'Aktive Tests';
$string['block:noattempts'] = 'Noch keine Versuche vorhanden.';
$string['block_catquiz_statistics:addinstance'] = 'CAT-Quiz-Statistik-Block hinzufügen';
$string['block_catquiz_statistics:export'] = 'Statistikdaten exportieren';
$string['block_catquiz_statistics:myaddinstance'] = 'CAT-Quiz-Statistik-Block zu Moodle-Startseite hinzufügen';
$string['block_catquiz_statistics:view'] = 'Aggregierte Statistik-Widgets anzeigen';
$string['block_catquiz_statistics:viewall'] = 'Systemweite Statistiken anzeigen';
$string['block_catquiz_statistics:viewdebug'] = 'Testverlauf und Debug-Daten anzeigen';
$string['block_catquiz_statistics:viewdetails'] = 'Detaillierten Bericht pro Nutzer anzeigen';
$string['error:nocatquiz'] = 'Dieser Bericht erfordert die Installation von local_catquiz.';
$string['error:nopermission'] = 'Sie haben keine Berechtigung, diesen Bericht einzusehen.';
$string['module_a'] = 'Testergebnisse';
$string['module_b'] = 'Testnutzung';
$string['module_c'] = 'Testverlauf';
$string['module_d'] = 'Lernangebotsnutzung';
$string['module_e'] = 'Item- und Antwortanalyse';
$string['nocatquiz'] = 'Das erforderliche Plugin local_catquiz ist nicht installiert oder nicht aktiv.';
$string['noinstances'] = 'Keine CAT-Quiz-Instanzen in diesem Kurs gefunden.';
$string['pluginname'] = 'CAT-Quiz-Statistik';
$string['pluginname:desc'] = 'Erweiterte Statistiken und Multi-Format-Datenexport für CAT-Quiz-Versuche.';
$string['privacy:metadata'] = 'Dieses Plugin liest ausschließlich Daten, die von local_catquiz und der Moodle-Frage-Engine verwaltet werden. Es speichert keine eigenen personenbezogenen Daten (Phase 1 / MVP).';
$string['privacy:metadata:adaptivequiz_attempt'] = 'Das Feld adaptivequiz_attempt.uniqueid wird gelesen, um CAT-Quiz-Versuche mit der Moodle-Frage-Engine zu verknüpfen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:local_catquiz_attempts'] = 'Versuchsdaten (Fähigkeit, SE, Strategie, Status, JSON-Nutzlast) werden aus dieser Tabelle für Statistiken und Exporte gelesen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:local_catquiz_personparams'] = 'Personenfähigkeitsparameter pro Skala und Kontext werden aus dieser Tabelle gelesen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:question_attempt_step_data'] = 'Schritt-Schlüssel-Wert-Paare werden für die Distraktoren- und Antwortoptionshäufigkeitsanalyse gelesen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:question_attempt_steps'] = 'Fragenversuchsschritte (Bruchteil, Erstellungszeitpunkt) werden für Timing- und Antwortanalysen gelesen. Dieses Plugin speichert keine Daten.';
$string['report:col_attempt_rank'] = 'Versuch Nr.';
$string['report:col_attemptid'] = 'Versuchs-ID';
$string['report:col_delta_ability'] = 'Faehigkeitsaenderung';
$string['report:col_diff_max'] = 'Schwierigkeit max';
$string['report:col_diff_mean'] = 'Schwierigkeit Mittel';
$string['report:col_diff_min'] = 'Schwierigkeit min';
$string['report:col_diff_sd'] = 'Schwierigkeit SD';
$string['report:col_difficulty'] = 'Schwierigkeit';
$string['report:col_duration_fmt'] = 'Dauer';
$string['report:col_duration_s'] = 'Dauer (s)';
$string['report:col_email'] = 'E-Mail';
$string['report:col_endtime'] = 'Endzeit';
$string['report:col_firstname'] = 'Vorname';
$string['report:col_fisherinformation'] = 'Fisher-Information';
$string['report:col_global_pp'] = 'Globalskala PP';
$string['report:col_global_scale_id'] = 'Globalskala ID';
$string['report:col_global_scale_name'] = 'Globalskala';
$string['report:col_global_se'] = 'Globalskala SE';
$string['report:col_group_difficulty'] = 'Itemschwierigkeiten';
$string['report:col_group_items'] = 'Items';
$string['report:col_group_results'] = 'Ergebnis';
$string['report:col_group_scaleinfo'] = 'Skaleninformation';
$string['report:col_id'] = '#';
$string['report:col_instanceid'] = 'Instanz-ID';
$string['report:col_items_parametrized'] = 'Anzahl parametrisiert';
$string['report:col_items_productive'] = 'Items (produktiv)';
$string['report:col_items_total'] = 'Items (gesamt)';
$string['report:col_lastname'] = 'Nachname';
$string['report:col_lastresponse'] = 'Antwort (Anteil)';
$string['report:col_max'] = 'Max';
$string['report:col_mean'] = 'Mittel';
$string['report:col_median'] = 'Median';
$string['report:col_min'] = 'Min';
$string['report:col_n'] = 'Anzahl';
$string['report:col_parent_label'] = 'Übergeordnete Skala';
$string['report:col_personability_after'] = 'Faehigkeit nach Schritt';
$string['report:col_primary_pp'] = 'Primärskala PP';
$string['report:col_primary_scale_id'] = 'Primärskala ID';
$string['report:col_primary_scale_name'] = 'Primärskala';
$string['report:col_primary_se'] = 'Primärskala SE';
$string['report:col_q1'] = 'Q1';
$string['report:col_q3'] = 'Q3';
$string['report:col_questionname'] = 'Frage';
$string['report:col_questionscale'] = 'Skala-ID';
$string['report:col_questionscale_name'] = 'Skalenname';
$string['report:col_rci'] = 'RCI';
$string['report:col_scale_id'] = 'ID';
$string['report:col_scale_label'] = 'Label';
$string['report:col_scale_name'] = 'Name';
$string['report:col_scale_parent'] = 'Zugeordnet';
$string['report:col_sd'] = 'SD';
$string['report:col_starttime'] = 'Startzeit';
$string['report:col_status'] = 'Status';
$string['report:col_step_nr'] = 'Schritt';
$string['report:col_testid'] = 'Test-ID';
$string['report:col_testname'] = 'Testname';
$string['report:col_teststrategy'] = 'Test-Strategie';
$string['report:col_total_testitems'] = 'Items gesamt';
$string['report:col_used_testitems'] = 'Items beantwortet';
$string['report:col_userid'] = 'User-ID';
$string['report:col_username'] = 'User';
$string['report:comingsoon'] = 'Der detaillierte Bericht wird in der nächsten Version verfügbar sein (Phase 1: Testergebnisse).';
$string['report:export_button'] = 'Exportieren';
$string['report:export_csv'] = 'CSV-Export';
$string['report:export_excel'] = 'Excel-Export (8 Blätter)';
$string['report:export_format'] = 'Exportformat';
$string['report:filter_all'] = '(alle)';
$string['report:filter_all_courses'] = '(alle Kurse)';
$string['report:filter_all_instances'] = '(alle Instanzen)';
$string['report:filter_apply'] = 'Filter anwenden';
$string['report:filter_course'] = 'Kurs';
$string['report:filter_enddate'] = 'Bis Datum';
$string['report:filter_instance'] = 'CAT-Quiz-Instanz';
$string['report:filter_instances'] = 'CAT-Quiz-Instanzen';
$string['report:filter_no_instances'] = 'Keine CAT-Quiz-Instanzen in diesem Kurs gefunden.';
$string['report:filter_none'] = '(kein Filter)';
$string['report:filter_startdate'] = 'Ab Datum';
$string['report:format_csv'] = 'CSV';
$string['report:format_excel'] = 'Excel (XLSX, 8 Blätter)';
$string['report:format_json'] = 'JSON';
$string['report:format_ods'] = 'ODS (8 Blätter)';
$string['report:grp_item_difficulty'] = 'Itemschwierigkeiten';
$string['report:grp_items'] = 'Items';
$string['report:grp_results'] = 'Ergebnis';
$string['report:grp_scale_info'] = 'Skaleninformation';
$string['report:meta_courseid'] = 'Kurs-ID';
$string['report:meta_coursename'] = 'Kursname';
$string['report:meta_datetime'] = 'Exportzeitpunkt';
$string['report:meta_format'] = 'Format';
$string['report:meta_instanceid'] = 'Instanz-ID (Adaptivequiz)';
$string['report:meta_key'] = 'Schlüssel';
$string['report:meta_moodle'] = 'Moodle-Version';
$string['report:meta_participants'] = 'Teilnehmer';
$string['report:meta_plugin'] = 'Plugin';
$string['report:meta_rootscale'] = 'Globalskala';
$string['report:meta_s_course'] = 'Kurs & Test';
$string['report:meta_s_export'] = 'Export-Informationen';
$string['report:meta_s_filter'] = 'Filter';
$string['report:meta_s_hierarchy'] = 'Skalenhierarchie';
$string['report:meta_s_results'] = 'Ergebnis';
$string['report:meta_s_se'] = 'SE-Validitäts-Einstellungen';
$string['report:meta_s_sheets'] = 'Blatt-Übersicht';
$string['report:meta_section'] = 'Abschnitt';
$string['report:meta_sheet_attempts_raw'] = 'Rohdaten (DB-Spalten, kein Wide-Format)';
$string['report:meta_sheet_attempts_wide'] = 'Flat/Wide inkl. aller Subskalenspalten';
$string['report:meta_sheet_scale_summary'] = 'Deskriptivstatistik je Skala (n/mean/median/sd/…)';
$string['report:meta_sheet_subscale_frac'] = 'Lösungsquote je Versuch × Subskala';
$string['report:meta_sheet_subscale_n'] = 'Itemanzahl je Versuch × Subskala';
$string['report:meta_sheet_subscale_scores'] = 'Personenfähigkeit (PP) je Versuch × Subskala';
$string['report:meta_sheet_subscale_se'] = 'Standard-SE je Versuch × Subskala (Validitätsprüfung)';
$string['report:meta_subof'] = 'Subskala von';
$string['report:meta_testid'] = 'Test-ID (catquiz)';
$string['report:meta_testname'] = 'Testname';
$string['report:meta_totalattempts'] = 'Versuche gesamt';
$string['report:meta_value'] = 'Wert';
$string['report:n_attempts'] = 'Gefundene Versuche:';
$string['report:noattempts'] = 'Keine Versuche entsprechen dem aktuellen Filter.';
$string['report:rci_note'] = 'RCI = Faehigkeitsaenderung / sqrt(SE1^2 + SE2^2); |RCI| >= 1,96 zeigt reliable Veraenderung an (p < .05).';
$string['report:se_invalid_note'] = 'NULL = SE-Validität nicht erfüllt (SE > SEmax={$a->semax} oder N < Nmin={$a->nmin})';
$string['report:sheet_attempts_raw'] = 'Testversuche';
$string['report:sheet_attempts_wide'] = 'Ergebnisse (gesamt)';
$string['report:sheet_metadata'] = 'Metadaten';
$string['report:sheet_scale_summary'] = 'Ergebnisbericht';
$string['report:sheet_subscale_frac'] = 'Ergebnisse (% Korrekt)';
$string['report:sheet_subscale_n'] = 'Ergebnisse (Frageanzahl)';
$string['report:sheet_subscale_scores'] = 'Ergebnisse (Scores)';
$string['report:sheet_subscale_se'] = 'Ergebnisse (Standardfehler)';
$string['report_schema_missing'] = 'CAT Quiz Statistics erfordert local_catquiz. Bitte wenden Sie sich an Ihren Moodle-Administrator.';
$string['reporttitle'] = 'CAT-Quiz-Statistik – Kursbericht';
$string['setting:defaultformat'] = 'Standard-Exportformat';
$string['setting:defaultformat_desc'] = 'Format, das standardmäßig beim Datenexport verwendet wird.';
$string['setting:enablemoduled'] = 'Lernangebotsnutzung aktivieren';
$string['setting:enablemoduled_desc'] = 'Wenn aktiviert, werden Protokolldaten zur Lernangebotsnutzung über die Aufbewahrungsrichtlinie der Plattform hinaus gesammelt. Erfordert eine ausdrückliche datenschutzrechtliche Begründung. Standardmäßig deaktiviert.';
$string['setting:enableqejoin'] = 'Frage-Engine-Daten aktivieren';
$string['setting:enableqejoin_desc'] = 'Wenn aktiviert, werden pro-Frage-Timing- und Antwortdaten aus der Moodle-Frage-Engine in den Testverlauf und die Item- und Antwortanalyse aufgenommen.';
$string['setting:maxsheets'] = 'Maximale Blätter pro Workbook-Export';
$string['setting:maxsheets_desc'] = 'Maximale Anzahl von Blättern in einem Multi-Sheet-XLSX/ODS-Export. Hohe Werte können zu Speicherproblemen führen.';
$string['viewadminreport'] = 'Systemweiter Bericht';
$string['viewreport'] = 'Bericht öffnen';
