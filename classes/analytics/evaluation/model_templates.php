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

namespace block_catquiz_statistics\analytics\evaluation;

/**
 * Generic evaluation-model templates (Issue #7).
 *
 * Templates only create a starting configuration (transitions); they remain
 * fully editable and contain no project names. Steps use the generic semantic
 * keys; variables and outcomes are assigned afterwards.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class model_templates {
    /** @var array Template => transitions. */
    private const STEPS = [
        'started' => ['key' => 'event:started:assessment', 'occasion' => 'first'],
        'completed' => ['key' => 'event:completed:assessment', 'occasion' => 'first'],
        'feedback' => ['key' => 'event:viewed:feedback', 'occasion' => 'first'],
        'recommendation' => ['key' => 'event:delivered:recommendation', 'occasion' => 'first'],
        'offerviewed' => ['key' => 'event:viewed:learning_activity', 'occasion' => 'first'],
        'offercompleted' => ['key' => 'event:completed:learning_activity', 'occasion' => 'first'],
        'retest' => ['key' => 'event:restarted:assessment', 'occasion' => 'first'],
    ];

    /** @var array Template definitions: list of step ids. */
    public const TEMPLATES = [
        'effectchain' => ['started', 'completed', 'feedback', 'recommendation', 'offerviewed', 'offercompleted', 'retest'],
        'acceptance_use' => ['started', 'completed', 'feedback'],
        'use_performance' => ['offerviewed', 'offercompleted', 'retest'],
        'performance_outcome' => ['completed', 'retest'],
        'incremental' => ['completed', 'offercompleted'],
        'prepost' => ['completed', 'retest'],
        'mediation' => ['feedback', 'offercompleted', 'retest'],
    ];

    /**
     * Starting configuration of a template.
     *
     * @param string $template Template key.
     * @return array config with population and transitions
     * @throws \coding_exception For unknown templates.
     */
    public static function config(string $template): array {
        if (!isset(self::TEMPLATES[$template])) {
            throw new \coding_exception('Unknown model template: ' . $template);
        }
        $transitions = [];
        foreach (self::TEMPLATES[$template] as $step) {
            $transitions[] = self::STEPS[$step] + [
                'label' => get_string('step:' . $step, 'block_catquiz_statistics'),
                'definition' => get_string('step:' . $step . ':definition', 'block_catquiz_statistics'),
            ];
        }
        return ['template' => $template, 'population' => [['type' => 'enrolled']], 'transitions' => $transitions];
    }
}
