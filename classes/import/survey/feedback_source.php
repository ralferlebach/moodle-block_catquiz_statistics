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

namespace block_catquiz_statistics\import\survey;

/**
 * mod_feedback as survey source.
 *
 * Supported item types: multichoicerated (ordinal, value = rated weight of the
 * chosen option), multichoice single (categorical, value = option text),
 * numeric, textfield/textarea (string). Multiple-answer multichoice and layout
 * items (label, info, pagebreak, captcha) are not imported. Only instances with
 * "anonymous = no" are read; "not selected" (value 0) is a declared missing code.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_source implements survey_source_interface {
    /** @var int mod_feedback: responses are not anonymous. */
    private const NOT_ANONYMOUS = 2;

    /**
     * Source key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'feedback';
    }

    /**
     * Component.
     *
     * @return string
     */
    public function get_component(): string {
        return 'mod_feedback';
    }

    /**
     * Whether mod_feedback is installed.
     *
     * @return bool
     */
    public function is_available(): bool {
        return \core_plugin_manager::instance()->get_plugin_info('mod_feedback') !== null;
    }

    /**
     * Instances of a course.
     *
     * @param int $courseid Course.
     * @return array cmid => name
     */
    public function get_instances(int $courseid): array {
        $result = [];
        foreach (get_fast_modinfo($courseid)->get_instances_of('feedback') as $cm) {
            $result[(int) $cm->id] = $cm->get_formatted_name();
        }
        return $result;
    }

    /**
     * Anonymous?
     *
     * @param int $cmid Course module.
     * @return bool
     */
    public function is_anonymous(int $cmid): bool {
        return (int) $this->instance($cmid)->anonymous !== self::NOT_ANONYMOUS;
    }

    /**
     * Importable items.
     *
     * @param int $cmid Course module.
     * @return array
     */
    public function get_items(int $cmid): array {
        global $DB;

        $items = [];
        $records = $DB->get_records('feedback_item', ['feedback' => $this->instance($cmid)->id, 'hasvalue' => 1], 'position, id');
        foreach ($records as $item) {
            $name = trim((string) $item->label) !== '' ? $item->label : 'item' . $item->id;
            $label = trim(strip_tags((string) $item->name)) ?: $name;
            $spec = null;
            switch ($item->typ) {
                case 'multichoicerated':
                    $weights = array_map(static fn($o) => (float) $o['weight'], self::options($item->presentation));
                    if ($weights) {
                        $spec = ['datatype' => 'ordinal', 'measurementlevel' => 'ordinal',
                            'allowedvalues' => ['min' => min($weights), 'max' => max($weights)]];
                    }
                    break;
                case 'multichoice':
                    if (self::subtype($item->presentation) !== 'c') {
                        $spec = ['datatype' => 'categorical', 'measurementlevel' => 'nominal',
                            'allowedvalues' => ['categories' => array_column(self::options($item->presentation), 'text')]];
                    }
                    break;
                case 'numeric':
                    $spec = ['datatype' => 'numeric', 'measurementlevel' => 'interval', 'allowedvalues' => null];
                    break;
                case 'textfield':
                case 'textarea':
                    $spec = ['datatype' => 'string', 'measurementlevel' => 'nominal', 'allowedvalues' => null];
                    break;
            }
            if ($spec !== null) {
                $items['item' . $item->id] = $spec + ['label' => $label, 'missingcodes' => [], 'name' => $name];
            }
        }
        return $items;
    }

    /**
     * Completed non-anonymous responses; option indices are translated into weights/texts.
     *
     * @param int $cmid Course module.
     * @return array
     */
    public function get_responses(int $cmid): array {
        global $DB;

        $feedback = $this->instance($cmid);
        if ((int) $feedback->anonymous !== self::NOT_ANONYMOUS) {
            return [];
        }
        $items = $DB->get_records('feedback_item', ['feedback' => $feedback->id, 'hasvalue' => 1]);
        $completed = $DB->get_records_select(
            'feedback_completed',
            'feedback = :fid AND userid > 0 AND anonymous_response = :notanon',
            ['fid' => $feedback->id, 'notanon' => self::NOT_ANONYMOUS],
            'timemodified, id',
            'id, userid, timemodified'
        );
        if (!$completed) {
            return [];
        }
        $rows = [];
        foreach ($completed as $c) {
            $rows[(int) $c->id] = ['responseid' => (int) $c->id, 'userid' => (int) $c->userid,
                'submitted' => (int) $c->timemodified, 'values' => []];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'cid');
        foreach ($DB->get_records_select('feedback_value', "completed $insql", $params) as $v) {
            $item = $items[(int) $v->item] ?? null;
            if ($item === null) {
                continue;
            }
            $rows[(int) $v->completed]['values']['item' . $item->id] = self::translate($item, (string) $v->value);
        }
        return array_values($rows);
    }

    /**
     * Translate a stored value into the raw import value.
     *
     * @param \stdClass $item Feedback item.
     * @param string $value Stored value.
     * @return string
     */
    private static function translate(\stdClass $item, string $value): string {
        if ($item->typ !== 'multichoicerated' && $item->typ !== 'multichoice') {
            return $value;
        }
        $index = (int) $value;
        if ($index <= 0) {
            return '';
        }
        $option = self::options($item->presentation)[$index - 1] ?? null;
        if ($option === null) {
            return $value;
        }
        return $item->typ === 'multichoicerated' ? (string) $option['weight'] : $option['text'];
    }

    /**
     * Subtype (r = radio, d = dropdown, c = checkbox) from a presentation string.
     *
     * @param string $presentation Presentation.
     * @return string
     */
    private static function subtype(string $presentation): string {
        return str_contains($presentation, '>>>>>') ? explode('>>>>>', $presentation, 2)[0] : 'r';
    }

    /**
     * Options of a (rated) multichoice item, in order.
     *
     * @param string $presentation Presentation, e.g. "r>>>>>1####low|5####high<<<<<1".
     * @return array list of ['weight' => ?string, 'text' => string]
     */
    private static function options(string $presentation): array {
        $body = str_contains($presentation, '>>>>>') ? explode('>>>>>', $presentation, 2)[1] : $presentation;
        $body = explode('<<<<<', $body, 2)[0];
        $options = [];
        foreach (explode('|', $body) as $line) {
            if (str_contains($line, '####')) {
                [$weight, $text] = explode('####', $line, 2);
                $options[] = ['weight' => trim($weight), 'text' => trim(strip_tags($text))];
            } else {
                $options[] = ['weight' => null, 'text' => trim(strip_tags($line))];
            }
        }
        return $options;
    }

    /**
     * Feedback record of a course module.
     *
     * @param int $cmid Course module.
     * @return \stdClass
     */
    private function instance(int $cmid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_id('feedback', $cmid, 0, false, MUST_EXIST);
        return $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);
    }
}
