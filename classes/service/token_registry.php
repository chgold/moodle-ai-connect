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
 * Token registry service — audit trail of every issued access token.
 *
 * The registry stores only the first 16 characters of each token so an
 * attacker who reads the registry table cannot use the values to call
 * the API. The prefix is enough to correlate audit events with the
 * working token row in {local_aiconnect_tokens}.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helpers for writing/reading the token registry audit table.
 */
class token_registry {

    /** @var int Number of leading characters of a token to persist. */
    public const PREFIX_LENGTH = 16;

    /**
     * Reduce a token to the registry prefix used for lookups.
     *
     * @param string $token Full opaque access token.
     * @return string Prefix (up to PREFIX_LENGTH chars).
     */
    public static function prefix(string $token): string {
        return substr($token, 0, self::PREFIX_LENGTH);
    }

    /**
     * Record an issued access token in the registry.
     *
     * @param string $token       Full access token.
     * @param int    $userid      Moodle user id the token authenticates as.
     * @param string $clientid    OAuth client identifier.
     * @param string $scope       Granted scopes.
     * @param int    $expiresat   Unix timestamp when the token expires.
     * @param string $source      How the token was issued (oauth|generate|refresh).
     * @return void
     */
    public static function register(string $token, int $userid, string $clientid,
            string $scope, int $expiresat, string $source = 'oauth'): void {
        global $DB;

        $DB->insert_record('local_aiconnect_token_registry', (object) [
            'token_prefix' => self::prefix($token),
            'user_id'      => $userid,
            'client_id'    => $clientid !== '' ? $clientid : 'unknown',
            'scope'        => $scope,
            'issued_at'    => time(),
            'expires_at'   => $expiresat,
            'last_used_at' => null,
            'revoked_at'   => null,
            'revoked_by'   => null,
            'source'       => $source,
            'ip_address'   => substr((string) getremoteaddr() ?: '', 0, 45),
        ]);
    }

    /**
     * Update the last_used_at timestamp for a token on a successful API call.
     *
     * @param string $token Full access token.
     */
    public static function touch(string $token): void {
        global $DB;

        $prefix = self::prefix($token);
        $rec = $DB->get_record_select(
            'local_aiconnect_token_registry',
            'token_prefix = :p AND revoked_at IS NULL',
            ['p' => $prefix],
            'id',
            IGNORE_MULTIPLE
        );
        if ($rec) {
            $DB->set_field('local_aiconnect_token_registry', 'last_used_at', time(), ['id' => $rec->id]);
        }
    }

    /**
     * Mark a token (by prefix) as revoked.
     *
     * @param string   $token     Full access token being revoked.
     * @param int|null $revokedby Moodle user id performing the revoke (null for self-revoke).
     */
    public static function revoke(string $token, ?int $revokedby = null): void {
        global $DB, $USER;

        $prefix = self::prefix($token);
        $now = time();
        $by = $revokedby ?? (isset($USER->id) ? (int) $USER->id : 0);

        $rec = $DB->get_record_select(
            'local_aiconnect_token_registry',
            'token_prefix = :p AND revoked_at IS NULL',
            ['p' => $prefix],
            'id',
            IGNORE_MULTIPLE
        );
        if ($rec) {
            $DB->set_field('local_aiconnect_token_registry', 'revoked_at', $now, ['id' => $rec->id]);
            $DB->set_field('local_aiconnect_token_registry', 'revoked_by', $by, ['id' => $rec->id]);
        }
    }

    /**
     * Check whether a token has been revoked in the registry.
     *
     * @param string $token Full access token.
     * @return bool True when registry has an entry with revoked_at IS NOT NULL.
     */
    public static function is_revoked(string $token): bool {
        global $DB;

        $prefix = self::prefix($token);
        return $DB->record_exists_select(
            'local_aiconnect_token_registry',
            'token_prefix = :p AND revoked_at IS NOT NULL',
            ['p' => $prefix]
        );
    }
}
