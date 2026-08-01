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
 * Edition-gated loader for the optional Pro tool-set plugins.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Discovers and registers separate Pro plugins' tool modules.
 *
 * Mirrors the WordPress/XenForo/Ghost model: Pro tool-sets live in separate
 * plugins (local_aiconnectteacher, local_aiconnectstudent, ...) and are loaded
 * into the core registry only when the site edition grants Pro AND each such
 * plugin is installed. Multi-Pro: any subset of known Pro plugins can be
 * installed side-by-side; only those with class_exists() succeed load.
 */
class pro_loader {

    /**
     * Known Pro plugin module classes, in load order.
     *
     * Adding a new sibling Pro plugin means adding one entry here — the class
     * only registers if edition=pro AND the plugin is installed (class_exists).
     * Absent plugins are skipped silently — this is the multi-Pro contract.
     *
     * @var string[]
     */
    private const PRO_MODULE_CLASSES = [
        '\\local_aiconnectteacher\\module\\pro_module',
        '\\local_aiconnectstudent\\module\\pro_module',
    ];

    /**
     * Register every installed Pro module into the given registry.
     *
     * @param module_registry $registry Registry being built.
     * @return int Count of Pro modules actually registered.
     */
    public static function load(module_registry $registry): int {
        $bundles = self::bundles();
        if (empty($bundles)) {
            return 0;
        }
        $loaded = 0;
        foreach (self::PRO_MODULE_CLASSES as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $registry->register(new $class());
            $loaded++;
        }
        return $loaded;
    }

    /**
     * Bundles granted by the current license (BUNDLES-LICENSE-SPEC v1.0).
     *
     * Returns:
     *   ['*']                → grant everything (default backward-compat for
     *                          fail-open / legacy `edition=pro` sites)
     *   ['teacher',...]       → grant only these bundle keys
     *   []                    → no bundles (equivalent to edition=free)
     *
     * @return string[]
     */
    public static function bundles(): array {
        if (self::edition() === 'pro') {
            return ['*'];
        }
        return [];
    }

    /**
     * Convenience check consumed by future bundle-aware Pro modules.
     *
     * @param string $bundle
     * @return bool
     */
    public static function has_bundle(string $bundle): bool {
        $b = self::bundles();
        return in_array('*', $b, true) || in_array($bundle, $b, true);
    }

    /**
     * Resolve the configured edition. Legacy accessor kept for the
     * bundle-less fallback path.
     *
     * @return string Lowercased edition string ('free' or 'pro').
     */
    private static function edition(): string {
        $env = getenv('AICONNECT_EDITION');
        if (is_string($env) && $env !== '') {
            return strtolower($env);
        }
        $cfg = get_config('local_aiconnect', 'edition');
        if (is_string($cfg) && $cfg !== '') {
            return strtolower($cfg);
        }
        return license_client::resolve_edition();
    }
}
