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
 * Research data export of an evaluation model as ZIP (Issue #8).
 *
 * Requires block/catquiz_statistics:export (dual rule as for all person-related
 * exports) and, for plain user ids, block/catquiz_statistics:exportidentified.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_catquiz_statistics\access;
use block_catquiz_statistics\research\export_bundle;

$courseid = required_param('courseid', PARAM_INT);
$modelid = required_param('modelid', PARAM_INT);
$mode = required_param('mode', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($course->id);
access::require_export($context);
if ($mode === 'identified') {
    require_capability('block/catquiz_statistics:exportidentified', $context);
} else if (!in_array($mode, ['stable', 'export'], true)) {
    throw new moodle_exception('invalidparameter', 'debug');
}
$model = $DB->get_record('block_catquiz_statistics_evalmodel', ['id' => $modelid, 'contextid' => $context->id], '*', MUST_EXIST);

core_php_time_limit::raise(300);
$files = (new export_bundle())->build($courseid, (int) $model->id, $mode);
$name = 'research_export_model' . $model->id . '_v' . $model->version . '_' . gmdate('Ymd_His');
$path = export_bundle::zip($files, $name);
send_file($path, $name . '.zip', 0, 0, false, true, 'application/zip');
