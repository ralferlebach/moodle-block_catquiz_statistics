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
 * German language strings for block_catquizstatistics.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Kern.
$string['pluginname']           = 'CAT-Quiz-Statistik';
$string['pluginname:desc']      = 'Erweiterte Statistiken und Mehformat-Datenexport für CAT-Quiz-Versuche.';

// Blockdarstellung.
$string['viewreport']           = 'Bericht öffnen';
$string['viewadminreport']      = 'Systemweiter Bericht';
$string['noinstances']          = 'Keine CAT-Quiz-Instanzen in diesem Kurs gefunden.';
$string['nocatquiz']            = 'Das erforderliche Plugin local_catquiz ist nicht installiert oder nicht aktiv.';

// Seitentitel.
$string['reporttitle']          = 'CAT-Quiz-Statistik – Kursbericht';
$string['adminreporttitle']     = 'CAT-Quiz-Statistik – Systembericht';

// Berichtsinhalte.
$string['report:comingsoon']    = 'Der detaillierte Bericht steht in der nächsten Version zur Verfügung (Phase 1: Testergebnisse).';
$string['adminreport:comingsoon'] = 'Die systemweite Item- und Antwortanalyse steht in einer späteren Version zur Verfügung (Phase 3: Modul e).';

// Rechtebeschreibungen.
$string['block_catquizstatistics:addinstance']   = 'CAT-Quiz-Statistik-Block hinzufügen';
$string['block_catquizstatistics:myaddinstance'] = 'CAT-Quiz-Statistik-Block zu Moodle-Startseite hinzufügen';
$string['block_catquizstatistics:view']          = 'Aggregierte Statistikübersicht anzeigen';
$string['block_catquizstatistics:viewdetails']   = 'Personenbezogene Berichtsdetails anzeigen';
$string['block_catquizstatistics:viewdebug']     = 'Testverläufe und Debug-Daten anzeigen';
$string['block_catquizstatistics:export']        = 'Statistikdaten exportieren';
$string['block_catquizstatistics:viewall']       = 'Systemweite Statistiken anzeigen';

// Administrationseinstellungen.
$string['setting:defaultformat']      = 'Standard-Exportformat';
$string['setting:defaultformat_desc'] = 'Format, das standardmäßig beim Datenexport verwendet wird.';
$string['setting:maxsheets']          = 'Maximale Tabellenblätter pro Arbeitsmappenexport';
$string['setting:maxsheets_desc']     = 'Maximale Anzahl von Blättern bei einem mehrseitigen XLSX-/ODS-Export. Hohe Werte können zu Speicherproblemen führen.';
$string['setting:enableqejoin']       = 'Question-Engine-Daten aktivieren';
$string['setting:enableqejoin_desc']  = 'Wenn aktiviert, werden pro Frage Zeitstempel- und Antwortdaten aus der Moodle-Question-Engine in Modul c (Testverlauf) und Modul e (Item- und Antwortanalyse) einbezogen.';
$string['setting:enablemoduled']      = 'Lernangebotsnutzungs-Modul aktivieren (Modul d)';
$string['setting:enablemoduled_desc'] = 'Wenn aktiviert, werden Lernaktivitäts-Protokolldaten über die Site-Aufbewahrungsfrist hinaus gespeichert. Erfordert eine explizite datenschutzrechtliche Grundlage. Standardmäßig deaktiviert.';

// Modulnamen (für UI-Tabs und Export-Blatttitel).
$string['module_a']     = 'Testergebnisse';
$string['module_b']     = 'Testnutzung';
$string['module_c']     = 'Testverlauf';
$string['module_d']     = 'Lernangebotsnutzung';
$string['module_e']     = 'Item- und Antwortanalyse';

// Fehler.
$string['error:nopermission']   = 'Sie haben keine Berechtigung, diesen Bericht anzuzeigen.';
$string['error:nocatquiz']      = 'Dieser Bericht erfordert, dass local_catquiz installiert ist.';

// Datenschutz.
$string['privacy:metadata']                          = 'Dieses Plugin liest ausschließlich Daten, die von local_catquiz und der Moodle-Question-Engine verwaltet werden. Es speichert keine eigenen personenbezogenen Daten (Phase 1 / MVP).';
$string['privacy:metadata:local_catquiz_attempts']   = 'Versuchsdaten (Personenfähigkeit, SE, Strategie, Status, JSON-Payload) werden aus dieser Tabelle gelesen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:local_catquiz_personparams'] = 'Personenfähigkeitsparameter je Skala und Kontext werden aus dieser Tabelle gelesen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:adaptivequiz_attempt']     = 'Das Feld adaptivequiz_attempt.uniqueid wird gelesen, um catquiz-Versuche mit der Question-Engine zu verknüpfen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:question_attempt_steps']   = 'Fragebeantwortungsschritte (fraction, timecreated) werden für die Antwortzeit- und Antwortanalyse gelesen. Dieses Plugin speichert keine Daten.';
$string['privacy:metadata:question_attempt_step_data'] = 'Key-Value-Paare der Antwortschritte werden für die Distraktor-/Antwortoptionshäufigkeitsanalyse gelesen. Dieses Plugin speichert keine Daten.';
