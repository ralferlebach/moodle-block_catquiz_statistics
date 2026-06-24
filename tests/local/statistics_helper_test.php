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

/**
 * PHPUnit tests for statistics_helper.
 *
 * Pure unit tests: no DB, no Moodle global state.
 * Percentile results are verified against Excel QUARTILE.INC values.
 * SD results are verified against the sample-SD (n-1) formula.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\local;

/**
 * Tests for statistics_helper.
 *
 * @covers \block_catquiz_statistics\local\statistics_helper
 */
final class statistics_helper_test extends \basic_testcase {
    /** @var float Floating-point tolerance for numerical assertions. */
    private const DELTA = 1e-9;

    /**
     * Empty array returns n=0 and all statistical fields null.
     *
     * @return void
     */
    public function test_descriptive_empty_array_returns_all_null(): void {
        $r = statistics_helper::descriptive([]);
        $this->assertSame(0, $r['n']);
        $this->assertNull($r['mean'], 'mean should be null for empty set');
        $this->assertNull($r['median'], 'median should be null for empty set');
        $this->assertNull($r['sd'], 'sd should be null for empty set');
        $this->assertNull($r['min'], 'min should be null for empty set');
        $this->assertNull($r['max'], 'max should be null for empty set');
        $this->assertNull($r['q1'], 'q1 should be null for empty set');
        $this->assertNull($r['q3'], 'q3 should be null for empty set');
    }

    /**
     * Array containing only null values is treated as empty.
     *
     * @return void
     */
    public function test_descriptive_null_only_array_treated_as_empty(): void {
        $r = statistics_helper::descriptive([null, null, null]);
        $this->assertSame(0, $r['n']);
        $this->assertNull($r['mean']);
    }

    /**
     * Single value: all measures equal that value; SD is null (undefined for n < 2).
     *
     * @return void
     */
    public function test_descriptive_single_value(): void {
        $r = statistics_helper::descriptive([5.0]);
        $this->assertSame(1, $r['n']);
        $this->assertEqualsWithDelta(5.0, $r['mean'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $r['median'], self::DELTA);
        $this->assertNull($r['sd']);
        $this->assertEqualsWithDelta(5.0, $r['min'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $r['max'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $r['q1'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $r['q3'], self::DELTA);
    }

    /**
     * Null values are excluded before computation; n reflects filtered count.
     *
     * Input: 1.0, null, 3.0, null, 5.0 — effective set is 1.0, 3.0, 5.0 (n=3).
     *
     * @return void
     */
    public function test_descriptive_nulls_are_excluded(): void {
        $r = statistics_helper::descriptive([1.0, null, 3.0, null, 5.0]);
        $this->assertSame(3, $r['n']);
        $this->assertEqualsWithDelta(3.0, $r['mean'], self::DELTA);
    }

    /**
     * Two-element array: Q1/Q3 use linear interpolation; SD defined (n >= 2).
     *
     * Verified values for input 2.0, 8.0:
     *   mean=5, median=5, Q1=3.5, Q3=6.5, SD=3*sqrt(2) approx 4.2426.
     *   Q1: pos=(n-1)*0.25=0.25 => 2+0.25*(8-2)=3.5.
     *   Q3: pos=(n-1)*0.75=0.75 => 2+0.75*(8-2)=6.5.
     *
     * @return void
     */
    public function test_descriptive_two_values(): void {
        $r = statistics_helper::descriptive([2.0, 8.0]);
        $this->assertSame(2, $r['n']);
        $this->assertEqualsWithDelta(5.0, $r['mean'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $r['median'], self::DELTA);
        $this->assertEqualsWithDelta(3.0 * sqrt(2.0), $r['sd'], self::DELTA);
        $this->assertEqualsWithDelta(2.0, $r['min'], self::DELTA);
        $this->assertEqualsWithDelta(8.0, $r['max'], self::DELTA);
        $this->assertEqualsWithDelta(3.5, $r['q1'], self::DELTA);
        $this->assertEqualsWithDelta(6.5, $r['q3'], self::DELTA);
    }

    /**
     * Odd-length array: median and quartiles land on exact integer positions (no interpolation).
     *
     * Verified values for input 1,2,3,4,5 (shuffled to test internal sort):
     *   mean=3, median=3, Q1=2, Q3=4, SD=sqrt(2.5).
     *   Q1: pos=4*0.25=1.0 => sorted index 1 = 2.0 (exact hit).
     *   Q3: pos=4*0.75=3.0 => sorted index 3 = 4.0 (exact hit).
     *
     * @return void
     */
    public function test_descriptive_odd_count_exact_quartiles(): void {
        $r = statistics_helper::descriptive([3.0, 1.0, 4.0, 2.0, 5.0]);
        $this->assertSame(5, $r['n']);
        $this->assertEqualsWithDelta(3.0, $r['mean'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $r['median'], self::DELTA);
        $this->assertEqualsWithDelta(sqrt(2.5), $r['sd'], self::DELTA);
        $this->assertEqualsWithDelta(1.0, $r['min'], self::DELTA);
        $this->assertEqualsWithDelta(5.0, $r['max'], self::DELTA);
        $this->assertEqualsWithDelta(2.0, $r['q1'], self::DELTA);
        $this->assertEqualsWithDelta(4.0, $r['q3'], self::DELTA);
    }

    /**
     * Even-length array: median and Q3 are interpolated; Q1 lands on exact position.
     *
     * Verified values for input 2,4,4,4,5,5,7,9:
     *   mean=5, median=4.5, Q1=4.0, Q3=5.5, SD=sqrt(32/7).
     *   Q1: pos=7*0.25=1.75 => 4+0.75*(4-4)=4.0 (diff=0, no interpolation).
     *   Q3: pos=7*0.75=5.25 => 5+0.25*(7-5)=5.5.
     *
     * @return void
     */
    public function test_descriptive_even_count_interpolated_quartiles(): void {
        $r = statistics_helper::descriptive([2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0]);
        $this->assertSame(8, $r['n']);
        $this->assertEqualsWithDelta(5.0, $r['mean'], self::DELTA);
        $this->assertEqualsWithDelta(4.5, $r['median'], self::DELTA);
        $this->assertEqualsWithDelta(sqrt(32.0 / 7.0), $r['sd'], self::DELTA);
        $this->assertEqualsWithDelta(2.0, $r['min'], self::DELTA);
        $this->assertEqualsWithDelta(9.0, $r['max'], self::DELTA);
        $this->assertEqualsWithDelta(4.0, $r['q1'], self::DELTA);
        $this->assertEqualsWithDelta(5.5, $r['q3'], self::DELTA);
    }

    /**
     * Four-element array: Q1, median, and Q3 are all interpolated between elements.
     *
     * Verified values for input 1,2,3,4:
     *   Q1: pos=3*0.25=0.75 => 1+0.75*(2-1)=1.75.
     *   median: pos=3*0.50=1.50 => 2+0.50*(3-2)=2.50.
     *   Q3: pos=3*0.75=2.25 => 3+0.25*(4-3)=3.25.
     *
     * @return void
     */
    public function test_descriptive_four_values_all_interpolated(): void {
        $r = statistics_helper::descriptive([1.0, 2.0, 3.0, 4.0]);
        $this->assertEqualsWithDelta(1.75, $r['q1'], self::DELTA);
        $this->assertEqualsWithDelta(2.5, $r['median'], self::DELTA);
        $this->assertEqualsWithDelta(3.25, $r['q3'], self::DELTA);
    }

    /**
     * Uniform values: SD=0, all quartiles equal the common value.
     *
     * @return void
     */
    public function test_descriptive_all_same_values(): void {
        $r = statistics_helper::descriptive([3.0, 3.0, 3.0]);
        $this->assertSame(3, $r['n']);
        $this->assertEqualsWithDelta(3.0, $r['mean'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $r['median'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $r['sd'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $r['q1'], self::DELTA);
        $this->assertEqualsWithDelta(3.0, $r['q3'], self::DELTA);
    }

    /**
     * Return array always contains all eight required keys for any input size.
     *
     * @return void
     */
    public function test_descriptive_return_has_all_keys(): void {
        $required = ['n', 'mean', 'median', 'sd', 'min', 'max', 'q1', 'q3'];
        foreach ([[], [1.0], [1.0, 2.0, 3.0]] as $input) {
            $r = statistics_helper::descriptive($input);
            foreach ($required as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $r,
                    "Key '$key' missing for input count " . count($input)
                );
            }
        }
    }

    /**
     * strategy_label maps known strategy constants 1–6 to German labels.
     *
     * @return void
     */
    public function test_strategy_label_known_values(): void {
        $this->assertSame('Alle Subskalen ableiten', statistics_helper::strategy_label(1));
        $this->assertSame('Niedrigste Subskala', statistics_helper::strategy_label(2));
        $this->assertSame('Pilot', statistics_helper::strategy_label(6));
    }

    /**
     * strategy_label falls back to "Strategie N" for unknown values and '' for null.
     *
     * @return void
     */
    public function test_strategy_label_fallback_and_null(): void {
        $this->assertSame('Strategie 8', statistics_helper::strategy_label(8));
        $this->assertSame('', statistics_helper::strategy_label(null));
    }

    /**
     * status_label maps 0 to "Abgeschlossen" and treats 4 as in progress.
     *
     * @return void
     */
    public function test_status_label_known_values(): void {
        $this->assertSame('Abgeschlossen', statistics_helper::status_label(0));
        $this->assertSame('In Bearbeitung', statistics_helper::status_label(1));
        $this->assertSame('In Bearbeitung', statistics_helper::status_label(4));
    }

    /**
     * status_label falls back to "Status N" for unknown values and '' for null.
     *
     * @return void
     */
    public function test_status_label_fallback_and_null(): void {
        $this->assertSame('Status 9', statistics_helper::status_label(9));
        $this->assertSame('', statistics_helper::status_label(null));
    }
}
