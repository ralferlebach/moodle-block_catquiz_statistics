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
 * CLI for synthetic demo cohorts (Issue #9).
 *
 * Examples:
 *   php blocks/catquiz_statistics/cli/demo.php --generate --seed=2026 --size=120 --profile=balanced
 *   php blocks/catquiz_statistics/cli/demo.php --list
 *   php blocks/catquiz_statistics/cli/demo.php --reset=3
 *
 * Requires the site setting block_catquiz_statistics/enabledemo.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use block_catquiz_statistics\demo\cohort_generator;
use block_catquiz_statistics\demo\scenario;

[$options, $unrecognised] = cli_get_params(
    ['generate' => false, 'seed' => 2026, 'size' => 100, 'profile' => 'balanced', 'classes' => '',
        'list' => false, 'reset' => 0, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || (!$options['generate'] && !$options['list'] && !$options['reset'])) {
    cli_writeln("Synthetic demo cohorts for block_catquiz_statistics (SYNTHETIC DEMO DATA).

--generate          Generate a cohort (--seed, --size, --profile, --classes)
--seed=INT          Seed (default 2026). Same seed + configuration = same cohort.
--size=INT          Cohort size 1..2000 (default 100)
--profile=NAME      " . implode(' | ', array_keys(scenario::PROFILES)) . "
--classes=LIST      Comma list of " . implode(',', cohort_generator::CLASSES) . " (default all)
--list              List registered demo runs
--reset=ID          Remove everything demo run ID created (and nothing else)");
    exit(0);
}
if (!get_config('block_catquiz_statistics', 'enabledemo')) {
    cli_error(get_string('demo:error:disabled', 'block_catquiz_statistics'));
}
\core\session\manager::set_user(get_admin());
$generator = new cohort_generator();

if ($options['generate']) {
    $classes = $options['classes'] === '' ? cohort_generator::CLASSES : array_map('trim', explode(',', $options['classes']));
    $id = $generator->generate((int) $options['seed'], (int) $options['size'], $options['profile'], $classes);
    $run = $DB->get_record(cohort_generator::TABLE, ['id' => $id]);
    cli_writeln("Demo run $id created: course id {$run->courseid} — " . get_string('demo:banner', 'block_catquiz_statistics'));
}
if ($options['list']) {
    foreach (cohort_generator::get_runs() as $run) {
        cli_writeln(sprintf(
            '%4d  seed=%-8d profile=%-26s n=%-5d course=%d  %s',
            $run->id,
            $run->seed,
            $run->profile,
            $run->cohortsize,
            $run->courseid,
            userdate($run->timecreated)
        ));
    }
}
if ($options['reset']) {
    $generator->reset((int) $options['reset']);
    cli_writeln('Demo run ' . (int) $options['reset'] . ' removed.');
}
