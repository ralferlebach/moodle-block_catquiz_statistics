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
 * Demo scenario profiles (Issue #9).
 *
 * SIMULATED parameters for demonstrations only — never empirical distributions.
 * Simulated structure (all effects configurable per profile):
 *   prior ability       ~ N(abilitymean, 1)
 *   self-efficacy       = 0.4 * prior + N(0, 0.9)
 *   uptake probability  = logistic(uptakebase + uptakeslope * self-efficacy)
 *   learning offers     completed only by users who take up offers
 *   theta T1            = prior + growth + learneffect * completed offers + N(0, 0.35)
 *   exam participation  = logistic(1.2 + 0.8 * theta T1)
 *   exam points         = clamp(55 + 15 * theta T1 + N(0, 8), 0, 100)
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scenario {
    /** @var array Profile parameters. */
    public const PROFILES = [
        'balanced' => ['abilitymean' => 0.0, 'uptakebase' => 0.0, 'uptakeslope' => 1.0, 'learneffect' => 0.15, 'growth' => 0.05],
        'highuptake_lowlearning' => ['abilitymean' => 0.0, 'uptakebase' => 1.6, 'uptakeslope' => 0.4, 'learneffect' => 0.02,
            'growth' => 0.0],
        'lowuptake_highperformance' => ['abilitymean' => 0.3, 'uptakebase' => -1.4, 'uptakeslope' => 1.2, 'learneffect' => 0.25,
            'growth' => 0.05],
        'mixed' => null,
    ];

    /**
     * Parameters for one person (mixed draws a concrete profile per person).
     *
     * @param string $profile Profile key.
     * @param rng $rng Generator.
     * @return array
     * @throws \coding_exception For unknown profiles.
     */
    public static function params(string $profile, rng $rng): array {
        if (!array_key_exists($profile, self::PROFILES)) {
            throw new \coding_exception('Unknown demo profile: ' . $profile);
        }
        if ($profile === 'mixed') {
            $profile = (string) $rng->pick(['balanced' => 2, 'highuptake_lowlearning' => 1, 'lowuptake_highperformance' => 1]);
        }
        return self::PROFILES[$profile] + ['profile' => $profile];
    }

    /**
     * Logistic function.
     *
     * @param float $x Value.
     * @return float
     */
    public static function logistic(float $x): float {
        return 1.0 / (1.0 + exp(-$x));
    }
}
