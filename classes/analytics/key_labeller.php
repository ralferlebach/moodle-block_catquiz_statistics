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

namespace block_catquiz_statistics\analytics;

use block_catquiz_statistics\analytics\semantic\semantic_label;

/**
 * Human readable labels for variable keys (var:, construct:, outcome:, event:, catquiz:).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class key_labeller {
    /** @var array Cache key => label. */
    private array $cache = [];

    /**
     * Label of a key.
     *
     * @param string $key Variable key.
     * @return string
     */
    public function label(string $key): string {
        global $DB;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        [$prefix, $rest] = array_pad(explode(':', $key, 2), 2, '');
        $label = match ($prefix) {
            'var' => $DB->get_field('block_catquiz_statistics_variable', 'label', ['id' => (int) $rest]),
            'construct' => $DB->get_field('block_catquiz_statistics_construct', 'label', ['id' => (int) $rest]),
            'outcome' => $DB->get_field('block_catquiz_statistics_outcome', 'label', ['id' => (int) $rest]),
            'event' => semantic_label::for_key($key),
            'catquiz' => get_string('key:catquizability', 'block_catquiz_statistics', (int) substr($rest, strrpos($rest, ':') + 1)),
            default => null,
        };
        return $this->cache[$key] = format_string($label ?: $key);
    }

    /**
     * Source description of a key (for the operationalisation view).
     *
     * @param string $key Variable key.
     * @return string
     */
    public function source(string $key): string {
        $prefix = explode(':', $key, 2)[0];
        return get_string('source:' . (in_array($prefix, ['var', 'construct', 'outcome', 'event', 'catquiz'], true)
            ? $prefix : 'other'), 'block_catquiz_statistics');
    }
}
