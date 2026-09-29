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
 * Measurement occasion of a selector in an evaluation model.
 *
 * Makes "which measurement" part of the selector identity, so that e.g. the
 * baseline (first) and the re-test (last) of the same CAT scale can take
 * different roles within one model.
 *
 * Canonical string forms:
 *   any                 any occasion (all matching observations); '' is accepted as alias
 *   first | last        chronologically first / last observation
 *   attempt:N           N-th observation (1-based, chronological)
 *   tp:<label>          observations with timepoint label, e.g. tp:T0
 *   window:<from>-<to>  observations with sort time in [from, to] (unix timestamps)
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class occasion {
    /**
     * Constructor — use {@see self::from_string()}.
     *
     * @param string $kind any | first | last | attempt | tp | window
     * @param int|null $n Attempt number for kind attempt.
     * @param string|null $label Timepoint label for kind tp.
     * @param int|null $from Window start.
     * @param int|null $to Window end.
     */
    private function __construct(
        /** @var string Kind. */
        public readonly string $kind,
        /** @var int|null Attempt number. */
        public readonly ?int $n = null,
        /** @var string|null Timepoint label. */
        public readonly ?string $label = null,
        /** @var int|null Window start. */
        public readonly ?int $from = null,
        /** @var int|null Window end. */
        public readonly ?int $to = null,
    ) {
    }

    /**
     * Parse and validate an occasion string.
     *
     * @param string $value Occasion string.
     * @return self
     * @throws \coding_exception On invalid syntax.
     */
    public static function from_string(string $value): self {
        $value = trim($value);
        if ($value === '' || $value === 'any') {
            return new self('any');
        }
        if ($value === 'first' || $value === 'last') {
            return new self($value);
        }
        if (preg_match('/^attempt:([1-9]\d*)$/', $value, $m)) {
            return new self('attempt', n: (int) $m[1]);
        }
        if (preg_match('/^tp:([A-Za-z0-9_.\-]{1,40})$/', $value, $m)) {
            return new self('tp', label: $m[1]);
        }
        if (preg_match('/^window:(\d+)-(\d+)$/', $value, $m) && (int) $m[1] <= (int) $m[2]) {
            return new self('window', from: (int) $m[1], to: (int) $m[2]);
        }
        throw new \coding_exception('Invalid occasion: ' . $value);
    }

    /**
     * Canonical string form.
     *
     * @return string
     */
    public function to_string(): string {
        return match ($this->kind) {
            'any' => 'any',
            'first', 'last' => $this->kind,
            'attempt' => 'attempt:' . $this->n,
            'tp' => 'tp:' . $this->label,
            'window' => 'window:' . $this->from . '-' . $this->to,
        };
    }

    /**
     * Select the observations of ONE person and ONE variable that this occasion denotes.
     *
     * Observations without a usable time are ignored for first/last/attempt/window.
     *
     * @param observation[] $observations Observations of one user and one variable key.
     * @return observation[]
     */
    public function select(array $observations): array {
        if ($this->kind === 'any') {
            return array_values($observations);
        }
        if ($this->kind === 'tp') {
            return array_values(array_filter($observations, fn(observation $o) => $o->timepoint === $this->label));
        }
        $timed = array_values(array_filter($observations, static fn(observation $o) => $o->get_sorttime() !== null));
        usort($timed, static fn(observation $a, observation $b) =>
            [$a->get_sorttime(), $a->sourcekey] <=> [$b->get_sorttime(), $b->sourcekey]);
        return match ($this->kind) {
            'first' => array_slice($timed, 0, 1),
            'last' => array_slice($timed, -1, 1),
            'attempt' => array_slice($timed, $this->n - 1, 1),
            'window' => array_values(array_filter(
                $timed,
                fn(observation $o) => $o->get_sorttime() >= $this->from && $o->get_sorttime() <= $this->to
            )),
        };
    }
}
