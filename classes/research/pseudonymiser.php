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

namespace block_catquiz_statistics\research;

/**
 * Pseudonyms for research exports (Issue #8).
 *
 * stable:     HMAC-SHA256(userid, site secret | scope) — the same person gets the same
 *             pseudonym within a scope (e.g. one evaluation model), enabling longitudinal
 *             linkage; not derivable from the userid without the secret.
 * export:     the scope additionally contains a fresh random salt that is never stored,
 *             so independent exports cannot be linked.
 * identified: plain userid — only with block/catquiz_statistics:exportidentified.
 *
 * The site secret is generated once, stored in the plugin config and never exported.
 * Pseudonymised data remain personal data (GDPR): pseudonymisation is not anonymisation.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pseudonymiser {
    /** @var string[] Modes. */
    public const MODES = ['stable', 'export', 'identified'];

    /** @var string Effective HMAC key material (secret + scope [+ salt]). */
    private string $key;

    /**
     * Constructor.
     *
     * @param string $mode stable | export | identified
     * @param string $scope Linkage scope, e.g. 'model:12'.
     * @throws \coding_exception For unknown modes.
     */
    public function __construct(
        /** @var string Mode. */
        public readonly string $mode,
        /** @var string Scope. */
        public readonly string $scope,
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \coding_exception('Unknown pseudonymisation mode: ' . $mode);
        }
        $salt = $mode === 'export' ? bin2hex(random_bytes(16)) : '';
        $this->key = self::secret() . '|' . $scope . '|' . $salt;
    }

    /**
     * Pseudonym (or plain id in identified mode) of a user.
     *
     * @param int $userid User.
     * @return string
     */
    public function subject(int $userid): string {
        if ($this->mode === 'identified') {
            return (string) $userid;
        }
        return 'P' . substr(hash_hmac('sha256', (string) $userid, $this->key), 0, 20);
    }

    /**
     * Site secret (created on first use, 256 bit).
     *
     * @return string
     */
    private static function secret(): string {
        $secret = get_config('block_catquiz_statistics', 'pseudonymsecret');
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            set_config('pseudonymsecret', $secret, 'block_catquiz_statistics');
        }
        return $secret;
    }
}
