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

namespace block_catquiz_statistics\import;

use block_catquiz_statistics\analytics\observation_status;

/**
 * Type inference and validated parsing of raw import values.
 *
 * No silent "cleaning": a value that violates type or range is kept as INVALID
 * together with its raw text. Declared missing codes become MISSING_CODED,
 * empty cells MISSING_NORECORD.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class value_parser {
    /** @var string[] Accepted boolean tokens (lower case) mapped to their value. */
    private const BOOLEANS = ['1' => true, '0' => false, 'true' => true, 'false' => false,
        'yes' => true, 'no' => false, 'ja' => true, 'nein' => false];

    /** @var int Integer columns with at most this many distinct values and min >= 0 are suggested as ordinal. */
    private const ORDINAL_MAXDISTINCT = 11;

    /**
     * Normalise a numeric string (accepts a decimal comma) or return null.
     *
     * @param string $raw Raw value.
     * @return float|null
     */
    public static function to_number(string $raw): ?float {
        $v = trim($raw);
        if (preg_match('/^-?\d+,\d+$/', $v)) {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Suggest datatype, measurement level and allowed values for a column.
     *
     * @param string[] $values Raw values of the column.
     * @param string[] $missingcodes Values to ignore.
     * @return array{datatype:string, measurementlevel:string, allowedvalues:?array}
     */
    public static function infer(array $values, array $missingcodes = []): array {
        $present = [];
        foreach ($values as $v) {
            $t = trim((string) $v);
            if ($t !== '' && !in_array($t, $missingcodes, true)) {
                $present[] = $t;
            }
        }
        if (empty($present)) {
            return ['datatype' => 'string', 'measurementlevel' => 'nominal', 'allowedvalues' => null];
        }
        $distinct = array_values(array_unique($present));

        $booltokens = ['true', 'false', 'yes', 'no', 'ja', 'nein'];
        if (count($distinct) <= 2 && !array_diff(array_map('strtolower', $distinct), $booltokens)) {
            return ['datatype' => 'boolean', 'measurementlevel' => 'nominal', 'allowedvalues' => null];
        }
        $numbers = array_map([self::class, 'to_number'], $present);
        if (!in_array(null, $numbers, true)) {
            $min = min($numbers);
            $max = max($numbers);
            $isint = !array_filter($numbers, static fn($n) => floor($n) != $n);
            if ($isint && count($distinct) <= self::ORDINAL_MAXDISTINCT && $min >= 0) {
                return ['datatype' => 'ordinal', 'measurementlevel' => 'ordinal',
                    'allowedvalues' => ['min' => $min, 'max' => $max]];
            }
            return ['datatype' => $isint ? 'integer' : 'numeric', 'measurementlevel' => 'interval', 'allowedvalues' => null];
        }
        if (!array_filter($present, static fn($v) => !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $v))) {
            return ['datatype' => 'datetime', 'measurementlevel' => 'interval', 'allowedvalues' => null];
        }
        if (count($distinct) <= max(20, (int) (count($present) / 2))) {
            sort($distinct);
            return ['datatype' => 'categorical', 'measurementlevel' => 'nominal', 'allowedvalues' => ['categories' => $distinct]];
        }
        return ['datatype' => 'string', 'measurementlevel' => 'nominal', 'allowedvalues' => null];
    }

    /**
     * Parse one raw value against a variable definition.
     *
     * @param string $raw Raw cell.
     * @param string $datatype Variable datatype.
     * @param array|null $allowed Allowed values (min/max or categories).
     * @param string[] $missingcodes Declared missing codes.
     * @return array{status:observation_status, numeric:?float, text:?string, bool:?bool}
     */
    public static function parse(string $raw, string $datatype, ?array $allowed, array $missingcodes): array {
        $t = trim($raw);
        $result = ['status' => observation_status::OBSERVED, 'numeric' => null, 'text' => null, 'bool' => null];
        if ($t === '') {
            $result['status'] = observation_status::MISSING_NORECORD;
            return $result;
        }
        if (in_array($t, $missingcodes, true)) {
            $result['status'] = observation_status::MISSING_CODED;
            $result['text'] = $t;
            return $result;
        }
        $invalid = ['status' => observation_status::INVALID, 'numeric' => null, 'text' => $t, 'bool' => null];

        switch ($datatype) {
            case 'boolean':
                $key = strtolower($t);
                if (!array_key_exists($key, self::BOOLEANS)) {
                    return $invalid;
                }
                $result['bool'] = self::BOOLEANS[$key];
                return $result;
            case 'numeric':
            case 'integer':
            case 'ordinal':
                $n = self::to_number($t);
                if ($n === null || ($datatype !== 'numeric' && floor($n) != $n)) {
                    return $invalid;
                }
                if (isset($allowed['min'], $allowed['max']) && ($n < $allowed['min'] || $n > $allowed['max'])) {
                    return $invalid;
                }
                $result['numeric'] = $n;
                return $result;
            case 'datetime':
                $ts = strtotime($t);
                if ($ts === false) {
                    return $invalid;
                }
                $result['numeric'] = (float) $ts;
                return $result;
            case 'categorical':
                if (!empty($allowed['categories']) && !in_array($t, $allowed['categories'], true)) {
                    return $invalid;
                }
                $result['text'] = $t;
                return $result;
            default:
                $result['text'] = $t;
                return $result;
        }
    }
}
