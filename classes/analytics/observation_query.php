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

/**
 * Immutable query scope for observation providers.
 *
 * The query is capability-agnostic: technically findable data is not
 * automatically visible data. Every consumer must check capabilities for the
 * contexts it passes in before displaying person-related results.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observation_query {
    /** @var int[]|null Restrict to these users (null = no restriction). */
    public readonly ?array $userids;

    /** @var int[]|null Restrict to these origin courses (null = all courses). */
    public readonly ?array $courseids;

    /** @var string[]|null Restrict to variable keys; an entry ending in '*' is a prefix match. */
    public readonly ?array $variablekeys;

    /**
     * Constructor.
     *
     * @param int[]|null $userids Restrict to these users.
     * @param int[]|null $courseids Restrict to these origin courses.
     * @param int|null $from Lower time bound (inclusive).
     * @param int|null $to Upper time bound (inclusive).
     * @param string[]|null $variablekeys Restrict to these variable keys (prefix with trailing '*').
     * @param bool|null $synthetic Null = real and synthetic, true = only synthetic, false = only real.
     */
    public function __construct(
        ?array $userids = null,
        ?array $courseids = null,
        /** @var int|null Lower time bound (inclusive). */
        public readonly ?int $from = null,
        /** @var int|null Upper time bound (inclusive). */
        public readonly ?int $to = null,
        ?array $variablekeys = null,
        /** @var bool|null Synthetic filter. */
        public readonly ?bool $synthetic = null,
    ) {
        $this->userids = self::clean_ids($userids);
        $this->courseids = self::clean_ids($courseids);
        $this->variablekeys = $variablekeys === null ? null : array_values(array_unique($variablekeys));
    }

    /**
     * Whether a variable key passes the variable-key filter.
     *
     * @param string $key Variable key.
     * @return bool
     */
    public function matches_variable(string $key): bool {
        if ($this->variablekeys === null) {
            return true;
        }
        foreach ($this->variablekeys as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($key, substr($pattern, 0, -1))) {
                    return true;
                }
            } else if ($pattern === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a whole key family (prefix) can be skipped entirely.
     *
     * @param string $prefix Key prefix a provider emits, e.g. 'catquiz:'.
     * @return bool True when no requested key can start with $prefix.
     */
    public function excludes_prefix(string $prefix): bool {
        if ($this->variablekeys === null) {
            return false;
        }
        foreach ($this->variablekeys as $pattern) {
            $stem = str_ends_with($pattern, '*') ? substr($pattern, 0, -1) : $pattern;
            if (str_starts_with($stem, $prefix) || str_starts_with($prefix, $stem)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a timestamp lies inside the time bounds (null timestamps pass).
     *
     * @param int|null $time Timestamp.
     * @return bool
     */
    public function matches_time(?int $time): bool {
        if ($time === null) {
            return true;
        }
        if ($this->from !== null && $time < $this->from) {
            return false;
        }
        if ($this->to !== null && $time > $this->to) {
            return false;
        }
        return true;
    }

    /**
     * Normalise an id list to distinct positive integers.
     *
     * @param array|null $ids Raw ids.
     * @return int[]|null Null when no restriction applies.
     */
    private static function clean_ids(?array $ids): ?array {
        if ($ids === null) {
            return null;
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    }
}
