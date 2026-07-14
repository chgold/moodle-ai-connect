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
 */
class license_client {

    /** @var string Default license validation endpoint. */
    private const API_URL = 'https://goldnat.ai/api/licenses/public/validate';

    /** @var string Config key holding the cached status JSON. */
    private const CACHE_KEY = 'license_status';

    /** @var int Cache lifetime in seconds (7 days). */
    private const CACHE_TTL = 604800;

    /** @var string[] Statuses that grant Pro. */
    private const VALID = ['valid', 'valid_no_updates'];

    /**
     * Resolve the effective edition from a license key.
     *
     * @return string 'pro' when a valid (or fail-open cached) license exists, else 'free'.
     */
    public static function resolve_edition(): string {
        $key = trim((string)(getenv('AICONNECT_LICENSE_KEY')
            ?: get_config('local_aiconnect', 'license_key')));
        if ($key === '') {
            return 'free';
        }

        $cached = self::read_cache();
        if ($cached !== null && isset($cached['checked_at'])
                && (time() - (int)$cached['checked_at']) < self::CACHE_TTL) {
            return in_array($cached['status'] ?? '', self::VALID, true) ? 'pro' : 'free';
        }

        $resp = self::call_server($key);
        if ($resp === null) {
            // Fail-open: never hard-block a paying customer on a network blip (mirrors XenForo).
            if ($cached !== null) {
                return in_array($cached['status'] ?? '', self::VALID, true) ? 'pro' : 'free';
            }
            self::write_cache(['valid' => true, 'status' => 'error_cached']);
            return 'pro';
        }

        self::write_cache($resp);
        return in_array($resp['status'] ?? '', self::VALID, true) ? 'pro' : 'free';
    }

    /**
     * @return array|null Decoded cache or null when absent.
     */
    private static function read_cache(): ?array {
        $raw = get_config('local_aiconnect', self::CACHE_KEY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array $status Status payload to persist.
     */
    private static function write_cache(array $status): void {
        $status['checked_at'] = time();
        set_config(self::CACHE_KEY, json_encode($status), 'local_aiconnect');
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
