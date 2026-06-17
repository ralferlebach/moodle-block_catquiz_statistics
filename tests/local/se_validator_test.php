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
 * PHPUnit tests for se_validator.
 *
 * Pure unit tests: no DB, no Moodle global state.
 * Tests mirror the filter_nminscale() / filter_semax() rules from
 * local_catquiz feedbacksettings.php.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\local;

/**
 * Tests for se_validator.
 *
 * @covers \block_catquiz_statistics\local\se_validator
 */
final class se_validator_test extends \basic_testcase {
    /** @var float Float comparison delta. */
    private const DELTA = 1e-9;

    /**
     * Null quizsettings returns permissive defaults: both thresholds null.
     *
     * @return void
     */
    public function test_extract_thresholds_null_settings_returns_nulls(): void {
        $r = se_validator::extract_thresholds(null);
        $this->assertNull($r['nminscale']);
        $this->assertNull($r['semax']);
    }

    /**
     * Full quizsettings: both thresholds extracted as correct types.
     *
     * The settings object mirrors local_catquiz testenvironment::return_settings(),
     * which is json_decode of local_catquiz_tests.json.
     *
     * @return void
     */
    public function test_extract_thresholds_full_settings(): void {
        $q = (object) [
            'catquiz_standarderrorgroup' => (object) [
                'catquiz_standarderror_max' => '0.35',
            ],
            'maxquestionsscalegroup' => (object) [
                'catquiz_minquestionspersubscale' => '4',
            ],
        ];
        $r = se_validator::extract_thresholds($q);
        $this->assertEqualsWithDelta(0.35, $r['semax'], self::DELTA);
        $this->assertSame(4, $r['nminscale']);
    }

    /**
     * Only semax configured; nminscale is null.
     *
     * @return void
     */
    public function test_extract_thresholds_semax_only(): void {
        $q = (object) [
            'catquiz_standarderrorgroup' => (object) [
                'catquiz_standarderror_max' => '0.30',
            ],
        ];
        $r = se_validator::extract_thresholds($q);
        $this->assertEqualsWithDelta(0.30, $r['semax'], self::DELTA);
        $this->assertNull($r['nminscale']);
    }

    /**
     * Only nminscale configured; semax is null.
     *
     * @return void
     */
    public function test_extract_thresholds_nminscale_only(): void {
        $q = (object) [
            'maxquestionsscalegroup' => (object) [
                'catquiz_minquestionspersubscale' => '3',
            ],
        ];
        $r = se_validator::extract_thresholds($q);
        $this->assertSame(3, $r['nminscale']);
        $this->assertNull($r['semax']);
    }

    /**
     * nminscale=0 in settings is treated as not configured (returns null).
     *
     * A zero threshold would pass all items unconditionally so it is suppressed.
     *
     * @return void
     */
    public function test_extract_thresholds_nminscale_zero_treated_as_null(): void {
        $q = (object) [
            'maxquestionsscalegroup' => (object) [
                'catquiz_minquestionspersubscale' => '0',
            ],
        ];
        $r = se_validator::extract_thresholds($q);
        $this->assertNull($r['nminscale']);
    }

    /**
     * Empty stdClass with no relevant keys: both thresholds null.
     *
     * @return void
     */
    public function test_extract_thresholds_empty_settings_object(): void {
        $r = se_validator::extract_thresholds((object) []);
        $this->assertNull($r['nminscale']);
        $this->assertNull($r['semax']);
    }

    /**
     * Empty graphicalsummary returns empty counts map.
     *
     * @return void
     */
    public function test_count_items_per_scale_empty(): void {
        $this->assertSame([], se_validator::count_items_per_scale([]));
    }

    /**
     * Items are counted correctly per scale from graphicalsummary steps.
     *
     * @return void
     */
    public function test_count_items_per_scale_counts_per_scale(): void {
        $graphical = [
            (object) ['questionscale' => 1, 'questionname' => 'q1'],
            (object) ['questionscale' => 2, 'questionname' => 'q2'],
            (object) ['questionscale' => 1, 'questionname' => 'q3'],
            (object) ['questionscale' => 2, 'questionname' => 'q4'],
            (object) ['questionscale' => 2, 'questionname' => 'q5'],
        ];
        $counts = se_validator::count_items_per_scale($graphical);
        $this->assertSame(2, $counts[1]);
        $this->assertSame(3, $counts[2]);
    }

    /**
     * Steps without a questionscale property are silently ignored.
     *
     * @return void
     */
    public function test_count_items_per_scale_ignores_missing_scale(): void {
        $graphical = [
            (object) ['questionscale' => 1],
            (object) ['questionname' => 'no_scale_property'],
        ];
        $counts = se_validator::count_items_per_scale($graphical);
        $this->assertSame(1, $counts[1]);
        $this->assertCount(1, $counts);
    }

    /**
     * No thresholds set: all SE values pass unchanged.
     *
     * @return void
     */
    public function test_validate_permissive_when_no_thresholds(): void {
        $se = [1 => 0.9, 2 => 0.01];
        $r = se_validator::validate($se, [], null, null);
        $this->assertEqualsWithDelta(0.9, $r[1], self::DELTA);
        $this->assertEqualsWithDelta(0.01, $r[2], self::DELTA);
    }

    /**
     * No thresholds set: even a very high SE passes.
     *
     * @return void
     */
    public function test_validate_no_thresholds_does_not_null_high_se(): void {
        $r = se_validator::validate([1 => 99.9], [], null, null);
        $this->assertNotNull($r[1]);
    }

    /**
     * SE at exactly semax is valid (boundary: <= passes).
     *
     * @return void
     */
    public function test_validate_se_at_semax_boundary_is_valid(): void {
        $r = se_validator::validate([1 => 0.35], [], null, 0.35);
        $this->assertEqualsWithDelta(0.35, $r[1], self::DELTA);
    }

    /**
     * SE strictly above semax yields null (invalid).
     *
     * @return void
     */
    public function test_validate_se_above_semax_returns_null(): void {
        $r = se_validator::validate([1 => 0.36], [], null, 0.35);
        $this->assertNull($r[1]);
    }

    /**
     * Mixed scales: scale 1 valid (SE <= semax), scale 2 invalid (SE > semax).
     *
     * @return void
     */
    public function test_validate_semax_mixed_validity(): void {
        $se = [1 => 0.25, 2 => 0.50];
        $r = se_validator::validate($se, [], null, 0.35);
        $this->assertEqualsWithDelta(0.25, $r[1], self::DELTA);
        $this->assertNull($r[2]);
    }

    /**
     * Scale with exactly nminscale items is valid (boundary: >= passes).
     *
     * @return void
     */
    public function test_validate_nminscale_boundary_passes(): void {
        $graphical = [
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 1],
        ];
        $r = se_validator::validate([1 => 0.3], $graphical, 3, null);
        $this->assertEqualsWithDelta(0.3, $r[1], self::DELTA);
    }

    /**
     * Scale below nminscale threshold yields null (invalid).
     *
     * @return void
     */
    public function test_validate_nminscale_below_threshold_returns_null(): void {
        $graphical = [
            (object) ['questionscale' => 2],
        ];
        $r = se_validator::validate([2 => 0.3], $graphical, 3, null);
        $this->assertNull($r[2]);
    }

    /**
     * Mixed scales: scale 1 has enough items (valid), scale 2 too few (invalid).
     *
     * Scale 1 has 3 items = nminscale => valid.
     * Scale 2 has 1 item < nminscale => invalid.
     *
     * @return void
     */
    public function test_validate_nminscale_mixed_validity(): void {
        $graphical = [
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 2],
        ];
        $se = [1 => 0.3, 2 => 0.3];
        $r = se_validator::validate($se, $graphical, 3, null);
        $this->assertEqualsWithDelta(0.3, $r[1], self::DELTA);
        $this->assertNull($r[2]);
    }

    /**
     * Scale absent from graphicalsummary has 0 items and therefore fails nminscale.
     *
     * @return void
     */
    public function test_validate_scale_absent_from_graphical_fails_nminscale(): void {
        $r = se_validator::validate([5 => 0.3], [], 1, null);
        $this->assertNull($r[5]);
    }

    /**
     * Both thresholds: a scale must pass both to remain valid.
     *
     * Test scenario (nminscale=3, semax=0.30):
     *   Scale 1: SE=0.25 (<=semax), N=3 (>=nminscale) => valid.
     *   Scale 2: SE=0.25 (<=semax), N=2 (<nminscale)  => invalid (fails nminscale).
     *   Scale 3: SE=0.35 (>semax),  N=4 (>=nminscale) => invalid (fails semax).
     *
     * @return void
     */
    public function test_validate_both_thresholds_must_pass(): void {
        $graphical = [
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 1],
            (object) ['questionscale' => 2],
            (object) ['questionscale' => 2],
            (object) ['questionscale' => 3],
            (object) ['questionscale' => 3],
            (object) ['questionscale' => 3],
            (object) ['questionscale' => 3],
        ];
        $se = [1 => 0.25, 2 => 0.25, 3 => 0.35];
        $r = se_validator::validate($se, $graphical, 3, 0.30);
        $this->assertEqualsWithDelta(0.25, $r[1], self::DELTA);
        $this->assertNull($r[2]);
        $this->assertNull($r[3]);
    }

    /**
     * Output map has exactly the same scale IDs as the input SE map.
     *
     * @return void
     */
    public function test_validate_output_keys_match_input_se_keys(): void {
        $se = [1 => 0.2, 3 => 0.4, 7 => 0.1];
        $r = se_validator::validate($se, [], null, null);
        $this->assertSame(array_keys($r), array_keys($se));
    }
}
