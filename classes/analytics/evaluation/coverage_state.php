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

use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_status;
use block_catquiz_statistics\analytics\value_type;

/**
 * Coverage of one analytic step for one person (Issue #7: "Datenabdeckung statt Scheingenauigkeit").
 *
 * Distinguishes "observed and positive", "observed and negative", "not applicable",
 * "not available" (missing/invalid) and "not observed" (no record at all). Only REACHED
 * passes a transition; nothing that was not observed is ever counted as negative.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum coverage_state: string {
    case REACHED = 'reached';
    case NEGATIVE = 'negative';
    case NOT_APPLICABLE = 'notapplicable';
    case UNAVAILABLE = 'unavailable';
    case NOT_OBSERVED = 'notobserved';

    /**
     * Classify the selected observations of one person for one step.
     *
     * @param observation[] $observations Observations selected by key and occasion.
     * @return self
     */
    public static function classify(array $observations): self {
        if (empty($observations)) {
            return self::NOT_OBSERVED;
        }
        $states = [];
        foreach ($observations as $o) {
            if ($o->status === observation_status::NOT_APPLICABLE) {
                $states[] = self::NOT_APPLICABLE;
            } else if (!$o->status->has_value()) {
                $states[] = self::UNAVAILABLE;
            } else if ($o->valuetype === value_type::BOOLEAN && $o->valuebool === false) {
                $states[] = self::NEGATIVE;
            } else {
                $states[] = self::REACHED;
            }
        }
        foreach ([self::REACHED, self::NEGATIVE, self::NOT_APPLICABLE, self::UNAVAILABLE] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }
        return self::NOT_OBSERVED;
    }

    /**
     * Whether the person has usable data for this step (positive or negative).
     *
     * @return bool
     */
    public function has_data(): bool {
        return $this === self::REACHED || $this === self::NEGATIVE;
    }
}
