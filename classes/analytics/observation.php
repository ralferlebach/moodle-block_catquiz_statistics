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
 * Immutable analytic observation — the common currency of all providers.
 *
 * An observation carries its full person, time, source and context identity.
 * It is independent of any block instance and of any evaluation model: the
 * analytic role is assigned later by the model, never stored here.
 *
 * Variable keys follow a small convention:
 *   var:<variableid>                imported/register variable
 *   construct:<constructid>         derived construct/subscale score
 *   catquiz:ability:<scaleid>       CAT person ability (attribute 'se')
 *   event:<action>:<objecttype>     semantic milestone / derived event
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observation {
    /**
     * Constructor.
     *
     * @param int $userid Canonical internal person identity.
     * @param string $variablekey Variable key (see class docblock).
     * @param string $sourcecomponent Frankenstyle component of the source.
     * @param string $sourcearea Functional source area.
     * @param string $sourcekey Unique source identity (idempotency).
     * @param int $origincontextid Moodle context the data point originates from.
     * @param value_type $valuetype Value type.
     * @param observation_status $status Observation status (incl. missing semantics).
     * @param int|null $origincourseid Course the data point originates from.
     * @param int|null $sourceitemid Source object id (attempt, cm, grade item ...).
     * @param int|null $occurredat Point in time of the observation.
     * @param int|null $periodstart Start of the observed period.
     * @param int|null $periodend End of the observed period.
     * @param string|null $timepoint Measurement occasion label (e.g. T0).
     * @param float|null $valuenumeric Numeric value.
     * @param string|null $valuetext Text/categorical value.
     * @param bool|null $valuebool Boolean value.
     * @param string|null $label Human readable label of the variable.
     * @param int|null $datasetid Dataset for imported observations.
     * @param int|null $variableid Register variable for imported observations.
     * @param int|null $constructid Construct for derived construct scores.
     * @param array $attributes Additional semantic attributes (e.g. se, occurrences).
     * @param array $provenance Minimal provenance information.
     * @param bool $issynthetic True for generated demo data.
     */
    public function __construct(
        /** @var int Canonical internal person identity. */
        public readonly int $userid,
        /** @var string Variable key. */
        public readonly string $variablekey,
        /** @var string Frankenstyle component of the source. */
        public readonly string $sourcecomponent,
        /** @var string Functional source area. */
        public readonly string $sourcearea,
        /** @var string Unique source identity. */
        public readonly string $sourcekey,
        /** @var int Origin context id. */
        public readonly int $origincontextid,
        /** @var value_type Value type. */
        public readonly value_type $valuetype,
        /** @var observation_status Observation status. */
        public readonly observation_status $status = observation_status::OBSERVED,
        /** @var int|null Origin course id. */
        public readonly ?int $origincourseid = null,
        /** @var int|null Source object id. */
        public readonly ?int $sourceitemid = null,
        /** @var int|null Point in time. */
        public readonly ?int $occurredat = null,
        /** @var int|null Period start. */
        public readonly ?int $periodstart = null,
        /** @var int|null Period end. */
        public readonly ?int $periodend = null,
        /** @var string|null Measurement occasion label. */
        public readonly ?string $timepoint = null,
        /** @var float|null Numeric value. */
        public readonly ?float $valuenumeric = null,
        /** @var string|null Text value. */
        public readonly ?string $valuetext = null,
        /** @var bool|null Boolean value. */
        public readonly ?bool $valuebool = null,
        /** @var string|null Label. */
        public readonly ?string $label = null,
        /** @var int|null Dataset id. */
        public readonly ?int $datasetid = null,
        /** @var int|null Variable id. */
        public readonly ?int $variableid = null,
        /** @var int|null Construct id. */
        public readonly ?int $constructid = null,
        /** @var array Additional attributes. */
        public readonly array $attributes = [],
        /** @var array Provenance. */
        public readonly array $provenance = [],
        /** @var bool Synthetic demo data flag. */
        public readonly bool $issynthetic = false,
    ) {
    }

    /**
     * The value in its natural PHP type, or null if not observed.
     *
     * @return float|string|bool|null
     */
    public function get_value(): float|string|bool|null {
        if (!$this->status->has_value()) {
            return null;
        }
        if ($this->valuetype === value_type::EVENT) {
            return true;
        }
        if ($this->valuetype === value_type::BOOLEAN) {
            return $this->valuebool;
        }
        if ($this->valuetype->is_numeric()) {
            return $this->valuenumeric;
        }
        return $this->valuetext;
    }

    /**
     * Best available point in time for chronological ordering.
     *
     * @return int|null
     */
    public function get_sorttime(): ?int {
        return $this->occurredat ?? $this->periodstart ?? $this->periodend;
    }

    /**
     * Build the variable key of a semantic event.
     *
     * @param semantic_action $action Action.
     * @param object_type $objecttype Object type.
     * @return string
     */
    public static function event_key(semantic_action $action, object_type $objecttype): string {
        return 'event:' . $action->value . ':' . $objecttype->value;
    }
}
