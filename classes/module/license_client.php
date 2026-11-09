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
 * License client for the Pro tool-set (validates against the Servio license server).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves the Pro edition from a verified license key.
 *
 * Per-plugin validation: each Pro sibling plugin (teacher/student/admin) has
 * its own license_key stored under its own component. is_valid_for() reads
 * the plugin-scoped key + caches the slug-matched status per plugin.
 */
class license_client {
    /** @var string Default license validation endpoint. */
    private const API_URL = 'https://goldnat.ai/api/licenses/public/validate';

    /** @var string Config key holding the cached status JSON (per plugin). */
    private const CACHE_KEY = 'license_status';

    /** @var int Cache lifetime in seconds (24h — aligned with refund policy). */
    private const CACHE_TTL = 86400;

    /** @var string[] Statuses that grant Pro. */
    private const VALID = ['valid', 'valid_no_updates'];

    /**
     * Slug-scoped grant check. Requires matching plugin_slug so another
     * plugin's license can't unlock this one. error_cached is the fail-open
     * network-blip verdict (no slug present) → honored.
     *
     * @param array|null $data Decoded validation response or cache.
     * @param string $expected_slug Slug this plugin expects on the response.
     * @return bool Whether Pro is granted for the expected slug.
     */
    private static function grants(?array $data, string $expected_slug): bool {
        if ($data === null) {
            return false;
        }
        if (($data['status'] ?? '') === 'error_cached') {
            return true;
        }
        return in_array($data['status'] ?? '', self::VALID, true)
            && ($data['plugin_slug'] ?? '') === $expected_slug;
    }

    /**
     * Whether the given Pro plugin has a valid license.
     *
     * @param string $component Moodle component name (e.g. 'local_aiconnectteacher').
     * @param string $expected_slug Servio slug this plugin expects (e.g. 'moodle-teacher-ai-connect').
     * @return bool True when a valid (or fail-open cached) license exists.
     */
    public static function is_valid_for(string $component, string $expected_slug): bool {
        $key = trim((string)(getenv('AICONNECT_LICENSE_KEY')
            ?: get_config($component, 'license_key')));
        if ($key === '') {
            return false;
        }

        $cached = self::read_cache($component);
        if (
            $cached !== null && isset($cached['checked_at'])
                && (time() - (int)$cached['checked_at']) < self::CACHE_TTL
        ) {
            return self::grants($cached, $expected_slug);
        }

        $resp = self::call_server($key);
        if ($resp === null) {
            // Fail-open on network failure — never hard-block paying customers.
            if ($cached !== null) {
                return self::grants($cached, $expected_slug);
            }
            self::write_cache($component, ['valid' => true, 'status' => 'error_cached']);
            return true;
        }

        self::write_cache($component, $resp);
        return self::grants($resp, $expected_slug);
    }

    /**
     * Legacy accessor kept for backward-compatible callers. Uses the teacher
     * slug as the historical default. New callers must use is_valid_for().
     *
     * @return string 'pro' or 'free'.
     */
    public static function resolve_edition(): string {
        return self::is_valid_for('local_aiconnect', 'moodle-teacher-ai-connect') ? 'pro' : 'free';
    }

    /**
     * @param string $component Moodle component the cache belongs to.
     * @return array|null Decoded cache or null when absent.
     */
    private static function read_cache(string $component): ?array {
        $raw = get_config($component, self::CACHE_KEY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $component Moodle component to scope the cache to.
     * @param array $status Status payload to persist.
     */
    private static function write_cache(string $component, array $status): void {
        $status['checked_at'] = time();
        set_config(self::CACHE_KEY, json_encode($status), $component);
    }

    /**
     * @param string $key License key.
     * @return array|null Decoded server response, or null on any failure.
     */
    private static function call_server(string $key): ?array {
        $url = getenv('AICONNECT_LICENSE_URL') ?: self::API_URL;
        $payload = json_encode([
            'license_key'   => $key,
            'domain'        => self::current_domain(),
            'addon_version' => get_config('local_aiconnect', 'version') ?: '1.0.0',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $code < 200 || $code >= 300 || !is_string($result) || $result === '') {
            return null;
        }
        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return string Lowercased host of the site URL.
     */
    private static function current_domain(): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot ?? '', PHP_URL_HOST);
        return strtolower($host ?: 'localhost');
    }
}
