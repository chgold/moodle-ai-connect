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
 * OAuth 2.1 redirect_uri rules (RFC 9700 §4.1, RFC 8252 §7.3).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\lib;

defined('MOODLE_INTERNAL') || die();

/**
 * Pure helpers — no database, no globals — so they are unit-testable.
 *
 * Rules (2.2.8):
 *  - the redirect_uri of an authorization request must be one of the client's
 *    registered URIs, compared as exact strings;
 *  - the only exception is a loopback redirect (http://127.0.0.1, http://[::1],
 *    http://localhost) registered for a native app: any port is accepted, the
 *    rest of the URI must match exactly (RFC 8252 §7.3);
 *  - an empty redirect_uri means the out-of-band flow and is accepted only when
 *    the client registered `urn:ietf:wg:oauth:2.0:oob`;
 *  - at the token endpoint the redirect_uri must equal the one stored with the
 *    code (an out-of-band code may omit it — no redirect happened).
 */
class redirect_uri {
    /** Out-of-band constant (code shown on our own page, no redirect). */
    public const OOB = 'urn:ietf:wg:oauth:2.0:oob';

    /**
     * Parse the stored `redirect_uris` column: JSON array, or a list separated
     * by whitespace / commas (legacy single plain value included).
     *
     * @param string|null $raw Column value.
     * @return string[] Registered URIs.
     */
    public static function registered_list(?string $raw): array {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $list = $decoded;
        } else if (is_string($decoded)) {
            $list = [$decoded];
        } else {
            $list = preg_split('/[\s,]+/', $raw);
        }
        $out = [];
        foreach ($list as $uri) {
            if (is_string($uri) && trim($uri) !== '') {
                $out[] = trim($uri);
            }
        }
        return $out;
    }

    /**
     * Empty and the OOB constant are the same thing.
     *
     * @param string|null $uri Redirect URI.
     * @return string Normalised value.
     */
    public static function normalise(?string $uri): string {
        $uri = (string) $uri;
        return $uri === '' ? self::OOB : $uri;
    }

    /**
     * Is $requested one of $registered (exact match, loopback-port exception)?
     *
     * @param string|null $requested Redirect URI from the request.
     * @param string[] $registered Registered URIs of the client.
     * @return bool
     */
    public static function is_registered(?string $requested, array $registered): bool {
        $requested = self::normalise($requested);
        foreach ($registered as $candidate) {
            $candidate = (string) $candidate;
            if (hash_equals($candidate, $requested)) {
                return true;
            }
            if (self::loopback_match($candidate, $requested)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Token-endpoint rule: the redirect_uri sent with the code exchange must be
     * the one used at authorization. An out-of-band code may omit it.
     *
     * @param string|null $stored Redirect URI saved with the code.
     * @param string|null $sent Redirect URI sent to the token endpoint (null = absent).
     * @return bool
     */
    public static function token_matches(?string $stored, ?string $sent): bool {
        $stored = self::normalise($stored);
        if ($sent === null || $sent === '') {
            return $stored === self::OOB;
        }
        return hash_equals($stored, self::normalise($sent));
    }

    /**
     * RFC 8252 §7.3 — a registered loopback URI matches the same URI on any port.
     *
     * @param string $registered Registered URI.
     * @param string $requested Requested URI.
     * @return bool
     */
    private static function loopback_match(string $registered, string $requested): bool {
        $r = parse_url($registered);
        $q = parse_url($requested);
        if (!is_array($r) || !is_array($q)) {
            return false;
        }
        if (strtolower($r['scheme'] ?? '') !== 'http' || strtolower($q['scheme'] ?? '') !== 'http') {
            return false;
        }
        $loopback = ['127.0.0.1', '[::1]', 'localhost'];
        $rhost = strtolower($r['host'] ?? '');
        if (!in_array($rhost, $loopback, true) || $rhost !== strtolower($q['host'] ?? '')) {
            return false;
        }
        if (isset($r['user']) || isset($r['pass']) || isset($q['user']) || isset($q['pass'])) {
            return false;
        }
        return ($r['path'] ?? '') === ($q['path'] ?? '')
            && ($r['query'] ?? null) === ($q['query'] ?? null)
            && !isset($r['fragment']) && !isset($q['fragment']);
    }
}
