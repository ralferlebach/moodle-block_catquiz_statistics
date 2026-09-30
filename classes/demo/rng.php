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

namespace block_catquiz_statistics\demo;

/**
 * Deterministic pseudo-random generator (mulberry32) with its own state.
 *
 * Independent of PHP's global mt_rand() state, which other code may reseed or
 * consume, so the same seed always yields the same synthetic cohort.
 * Not for cryptographic use.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rng {
    /** @var int 32-bit state. */
    private int $state;

    /**
     * Constructor.
     *
     * @param int $seed Seed.
     */
    public function __construct(int $seed) {
        $this->state = $seed & 0xFFFFFFFF;
    }

    /**
     * Uniform float in [0, 1).
     *
     * @return float
     */
    public function uniform(): float {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t = ($t ^ (($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    /**
     * Standard normal variate (Box-Muller).
     *
     * @param float $mean Mean.
     * @param float $sd Standard deviation.
     * @return float
     */
    public function normal(float $mean = 0.0, float $sd = 1.0): float {
        $u1 = max($this->uniform(), 1e-12);
        $u2 = $this->uniform();
        return $mean + $sd * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * Bernoulli draw.
     *
     * @param float $p Probability of true.
     * @return bool
     */
    public function chance(float $p): bool {
        return $this->uniform() < $p;
    }

    /**
     * Integer in [min, max].
     *
     * @param int $min Minimum.
     * @param int $max Maximum.
     * @return int
     */
    public function int(int $min, int $max): int {
        return $min + (int) floor($this->uniform() * ($max - $min + 1));
    }

    /**
     * Pick from weighted options.
     *
     * @param array $weights option => weight
     * @return int|string
     */
    public function pick(array $weights): int|string {
        $r = $this->uniform() * array_sum($weights);
        foreach ($weights as $option => $weight) {
            $r -= $weight;
            if ($r < 0) {
                return $option;
            }
        }
        return array_key_last($weights);
    }

    /**
     * 32-bit multiplication without float overflow.
     *
     * @param int $a Factor.
     * @param int $b Factor.
     * @return int
     */
    private static function imul(int $a, int $b): int {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $low = ($a & 0xFFFF) * $b;
        $high = ((($a >> 16) * $b) & 0xFFFF) << 16;
        return ($low + $high) & 0xFFFFFFFF;
    }
}
