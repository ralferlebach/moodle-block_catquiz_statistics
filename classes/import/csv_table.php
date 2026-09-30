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

/**
 * Parsed CSV content: header plus rows, with delimiter and encoding detection.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class csv_table {
    /** @var string[] Candidate delimiters in order of preference. */
    public const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Constructor.
     *
     * @param string[] $header Column names.
     * @param array $rows List of rows, each column name => raw string value.
     * @param string $delimiter Delimiter used.
     * @param string $encoding Source encoding.
     */
    public function __construct(
        /** @var string[] Column names. */
        public readonly array $header,
        /** @var array Rows (column => value). */
        public readonly array $rows,
        /** @var string Delimiter. */
        public readonly string $delimiter,
        /** @var string Source encoding. */
        public readonly string $encoding,
    ) {
    }

    /**
     * Parse CSV content.
     *
     * @param string $content Raw file content.
     * @param string|null $delimiter Delimiter or null for auto-detection.
     * @param string|null $encoding Source encoding or null for auto (UTF-8, else Windows-1252).
     * @return self
     * @throws \moodle_exception On empty content, missing/duplicate header or ragged rows.
     */
    public static function parse(string $content, ?string $delimiter = null, ?string $encoding = null): self {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if ($encoding === null) {
            $encoding = mb_check_encoding($content, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        }
        if (strtoupper($encoding) !== 'UTF-8') {
            $content = \core_text::convert($content, $encoding, 'UTF-8');
        }
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($content === '') {
            throw new \moodle_exception('import:error:empty', 'block_catquiz_statistics');
        }
        $delimiter = $delimiter ?? self::detect_delimiter(strtok($content, "\n"));

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $header = null;
        $rows = [];
        $line = 1;
        while (($fields = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($fields === [null]) {
                $line++;
                continue;
            }
            if ($header === null) {
                $header = array_map('trim', $fields);
                if (in_array('', $header, true) || count(array_unique($header)) !== count($header)) {
                    fclose($handle);
                    throw new \moodle_exception('import:error:header', 'block_catquiz_statistics');
                }
            } else {
                if (count($fields) !== count($header)) {
                    fclose($handle);
                    throw new \moodle_exception('import:error:columns', 'block_catquiz_statistics', '', $line);
                }
                $rows[] = array_combine($header, array_map(static fn($v) => (string) $v, $fields));
            }
            $line++;
        }
        fclose($handle);
        if ($header === null) {
            throw new \moodle_exception('import:error:empty', 'block_catquiz_statistics');
        }
        return new self($header, $rows, $delimiter, $encoding);
    }

    /**
     * Detect the delimiter from the header line (most frequent candidate outside quotes).
     *
     * @param string $line Header line.
     * @return string
     */
    public static function detect_delimiter(string $line): string {
        $unquoted = preg_replace('/"[^"]*"/', '', $line);
        $best = ',';
        $bestcount = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestcount) {
                $best = $candidate;
                $bestcount = $count;
            }
        }
        return $best;
    }

    /**
     * Canonical fingerprint of the content (independent of delimiter and encoding).
     *
     * @return string
     */
    public function fingerprint(): string {
        return sha1(json_encode([$this->header, $this->rows]));
    }
}
