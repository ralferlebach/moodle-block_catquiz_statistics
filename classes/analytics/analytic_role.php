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
 * Analytic roles of the evaluation model.
 *
 * Roles are assigned per evaluation model (see evalrole), never stored on a
 * data point. COVARIATE is orthogonal to the five classes of the effect chain.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum analytic_role: string {
    case DISPOSITION = 'disposition';
    case EXPOSURE = 'exposure';
    case BEHAVIOUR = 'behaviour';
    case PERFORMANCE = 'performance';
    case OUTCOME = 'outcome';
    case COVARIATE = 'covariate';

    /**
     * The five classes of the effect chain in their canonical order (without covariate).
     *
     * @return self[]
     */
    public static function chain(): array {
        return [self::DISPOSITION, self::EXPOSURE, self::BEHAVIOUR, self::PERFORMANCE, self::OUTCOME];
    }
}
