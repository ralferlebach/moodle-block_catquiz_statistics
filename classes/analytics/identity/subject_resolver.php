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

namespace block_catquiz_statistics\analytics\identity;

/**
 * Exact, auditable identity resolution against Moodle users.
 *
 * Rules (Issue #2): no assumption that idnumber holds a student number, no
 * silent multiple matches, no fuzzy matching. The only normalisation applied
 * is trimming surrounding whitespace. Deleted users never match.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subject_resolver implements subject_resolver_interface {
    /** @var int Chunk size for IN() queries. */
    private const CHUNK = 500;

    /**
     * Normalise an external identifier (trim only — no case folding, no fuzzing).
     *
     * @param string $externalid Raw identifier.
     * @return string
     */
    public static function normalise(string $externalid): string {
        return trim($externalid);
    }

    /**
     * Resolve external identifiers.
     *
     * @param string[] $externalids Raw identifiers.
     * @param string $matchfield Strategy.
     * @return resolution_result
     * @throws \coding_exception For unknown strategies.
     */
    public function resolve(array $externalids, string $matchfield): resolution_result {
        $ids = [];
        $unmatched = [];
        foreach ($externalids as $raw) {
            $key = self::normalise((string) $raw);
            if ($key === '') {
                continue;
            }
            $ids[$key] = $key;
        }

        $candidates = $this->find_candidates(array_values($ids), $matchfield);

        $matched = [];
        $ambiguous = [];
        foreach ($ids as $key) {
            $users = $candidates[$key] ?? [];
            if (count($users) === 1) {
                $matched[$key] = (int) reset($users);
            } else if (count($users) > 1) {
                $ambiguous[$key] = count($users);
            } else {
                $unmatched[] = $key;
            }
        }
        return new resolution_result($matchfield, $matched, $unmatched, $ambiguous);
    }

    /**
     * Available strategies incl. custom text profile fields.
     *
     * @return array
     */
    public function get_matchfields(): array {
        global $DB;

        $fields = [
            'userid' => get_string('userid', 'grades'),
            'idnumber' => get_string('idnumber'),
            'username' => get_string('username'),
        ];
        $profile = $DB->get_records_select('user_info_field', "datatype = 'text'", null, 'sortorder', 'id, shortname, name');
        foreach ($profile as $f) {
            $fields['profile_field_' . $f->shortname] = format_string($f->name);
        }
        return $fields;
    }

    /**
     * Collect candidate userids per external identifier.
     *
     * @param string[] $ids Normalised identifiers.
     * @param string $matchfield Strategy.
     * @return array identifier => int[] userids
     */
    private function find_candidates(array $ids, string $matchfield): array {
        global $DB;

        $result = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            if ($matchfield === 'userid') {
                $numeric = array_values(array_filter($chunk, static fn($v) => ctype_digit($v)));
                if (empty($numeric)) {
                    continue;
                }
                [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $numeric), SQL_PARAMS_NAMED, 'sr');
                $sql = "SELECT id AS userid, id AS matchvalue FROM {user} WHERE deleted = 0 AND id $insql";
            } else if ($matchfield === 'idnumber' || $matchfield === 'username') {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'sr');
                $sql = "SELECT id AS userid, $matchfield AS matchvalue
                          FROM {user}
                         WHERE deleted = 0 AND $matchfield $insql";
            } else if (str_starts_with($matchfield, 'profile_field_')) {
                $shortname = substr($matchfield, strlen('profile_field_'));
                $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
                if (!$fieldid) {
                    throw new \coding_exception('Unknown profile field: ' . $shortname);
                }
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'sr');
                $params['fieldid'] = $fieldid;
                $datacol = $DB->sql_compare_text('d.data', 255);
                $sql = "SELECT d.id, d.userid, d.data AS matchvalue
                          FROM {user_info_data} d
                          JOIN {user} u ON u.id = d.userid AND u.deleted = 0
                         WHERE d.fieldid = :fieldid AND $datacol $insql";
            } else {
                throw new \coding_exception('Unknown match field: ' . $matchfield);
            }

            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $row) {
                $key = self::normalise((string) $row->matchvalue);
                $result[$key][(int) $row->userid] = (int) $row->userid;
            }
            $rs->close();
        }
        return $result;
    }
}
