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
 * Reliable change between two person-parameter estimates (Issue #7).
 *
 * RCI = (theta2 - theta1) / SEdiff with SEdiff = sqrt(SE1^2 + SE2^2).
 * ASSUMPTION: the measurement errors of both estimates are independent
 * (covariance 0). Criterion: |RCI| > 1.96 (two-sided, 95 %). A missing,
 * invalid or non-positive SE makes the change NOT computable — it is never
 * treated as 0.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reliable_change {
    /** @var float Two-sided 95 % criterion. */
    public const Z = 1.96;

    /** @var string Documented assumption. */
    public const ASSUMPTION = 'SEdiff = sqrt(SE1^2 + SE2^2); independent measurement errors (covariance 0); |RCI| > 1.96';

    /**
     * Compute the reliable change.
     *
     * @param float|null $theta1 First estimate.
     * @param float|null $se1 Its standard error.
     * @param float|null $theta2 Second estimate.
     * @param float|null $se2 Its standard error.
     * @return array status (ok|notcomputable), reason, delta, sediff, cilow, cihigh, rci, reliable, direction
     */
    public static function compute(?float $theta1, ?float $se1, ?float $theta2, ?float $se2): array {
        $base = ['status' => 'notcomputable', 'reason' => null, 'delta' => null, 'sediff' => null, 'cilow' => null,
            'cihigh' => null, 'rci' => null, 'reliable' => null, 'direction' => null, 'assumption' => self::ASSUMPTION];
        if ($theta1 === null || $theta2 === null) {
            return ['reason' => 'missingestimate'] + $base;
        }
        $delta = $theta2 - $theta1;
        if ($se1 === null || $se2 === null || $se1 <= 0 || $se2 <= 0) {
            return ['reason' => 'missingse', 'delta' => $delta] + $base;
        }
        $sediff = sqrt($se1 ** 2 + $se2 ** 2);
        $rci = $delta / $sediff;
        $reliable = abs($rci) > self::Z;
        return [
            'status' => 'ok',
            'reason' => null,
            'delta' => $delta,
            'sediff' => $sediff,
            'cilow' => $delta - self::Z * $sediff,
            'cihigh' => $delta + self::Z * $sediff,
            'rci' => $rci,
            'reliable' => $reliable,
            'direction' => $reliable ? ($delta > 0 ? 'improved' : 'declined') : 'nochange',
            'assumption' => self::ASSUMPTION,
        ];
    }
}
