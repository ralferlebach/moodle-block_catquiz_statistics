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

namespace block_catquiz_statistics\analytics\identity;

/**
 * Outcome of an identity resolution run.
 *
 * Only unambiguous exact matches end up in $matched. Ambiguous and unmatched
 * identifiers are reported separately and never silently assigned.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resolution_result {
    /**
     * Constructor.
     *
     * @param string $matchfield Strategy used.
     * @param array $matched External id => userid.
     * @param string[] $unmatched External ids without candidate.
     * @param array $ambiguous External id => number of candidates (> 1).
     */
    public function __construct(
        /** @var string Strategy used. */
        public readonly string $matchfield,
        /** @var array External id => userid. */
        public readonly array $matched,
        /** @var string[] External ids without candidate. */
        public readonly array $unmatched,
        /** @var array External id => number of candidates. */
        public readonly array $ambiguous,
    ) {
    }

    /**
     * Counts for the import preview.
     *
     * @return array{matched:int, unmatched:int, ambiguous:int}
     */
    public function get_counts(): array {
        return [
            'matched' => count($this->matched),
            'unmatched' => count($this->unmatched),
            'ambiguous' => count($this->ambiguous),
        ];
    }

    /**
     * Resolved userid for an external id, or null if not unambiguously matched.
     *
     * @param string $externalid External identifier.
     * @return int|null
     */
    public function get_userid(string $externalid): ?int {
        return $this->matched[subject_resolver::normalise($externalid)] ?? null;
    }
}
