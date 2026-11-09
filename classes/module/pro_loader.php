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
     * Known Pro plugin module descriptors, in load order.
     *
     * Each entry:
     *   class     — fully-qualified pro_module class name
     *   component — Moodle component (holds the license_key config option)
     *   slug      — Servio plugin slug the license server must match
     *
     * Adding a new sibling Pro plugin means adding one entry here. Each Pro
     * loads only if BOTH the class exists (plugin installed) AND either the
     * environment override OR its own license is valid.
     *
     * @var array<int,array{class:string,component:string,slug:string}>
     */
    private const PRO_MODULES = [
        [
            'class'     => '\\local_aiconnectteacher\\module\\pro_module',
            'component' => 'local_aiconnectteacher',
            'slug'      => 'moodle-teacher-ai-connect',
        ],
        [
            'class'     => '\\local_aiconnectstudent\\module\\pro_module',
            'component' => 'local_aiconnectstudent',
            'slug'      => 'moodle-student-ai-connect',
        ],
        [
            'class'     => '\\local_aiconnectadmin\\module\\pro_module',
            'component' => 'local_aiconnectadmin',
            'slug'      => 'moodle-admin-ai-connect',
        ],
        [
            'class'     => '\\local_aiconnectbbb\\module\\pro_module',
            'component' => 'local_aiconnectbbb',
            'slug'      => 'moodle-bigbluebutton-ai-connect',
        ],
        [
            'class'     => '\\local_aiconnecthvp\\module\\pro_module',
            'component' => 'local_aiconnecthvp',
            'slug'      => 'moodle-hvp-ai-connect',
        ],
    ];

    /**
     * Register every installed + licensed Pro module into the given registry.
     *
     * Env override (AICONNECT_EDITION=pro) unlocks all Pro modules — used for
     * dev / test / CI where a real license is not practical.
     *
     * @param module_registry $registry Registry being built.
     * @return int Count of Pro modules actually registered.
     */
    public static function load(module_registry $registry): int {
        $env_pro = self::env_pro();
        $loaded = 0;
        foreach (self::PRO_MODULES as $mod) {
            if (!class_exists($mod['class'])) {
                continue;
            }
            if (!$env_pro && !license_client::is_valid_for($mod['component'], $mod['slug'])) {
                continue;
            }
            $registry->register(new $mod['class']());
            $loaded++;
        }
        return $loaded;
    }

    /**
     * Admin/dev override. Either source unlocks all Pro modules regardless
     * of license state — used for local dev, CI, and test sites without a
     * real license. Env takes precedence over the site-config value.
     *
     * @return bool True when the override grants Pro.
     */
    private static function env_pro(): bool {
        $env = getenv('AICONNECT_EDITION');
        if (is_string($env) && strtolower($env) === 'pro') {
            return true;
        }
        $cfg = get_config('local_aiconnect', 'edition');
        return is_string($cfg) && strtolower($cfg) === 'pro';
    }

    /**
     * Bundles granted by the current license, kept for backward compatibility.
     *
     * Returns ['*'] under the env override, otherwise walks the per-plugin
     * license state and returns the granted slugs.
     *
     * @return string[]
     */
    public static function bundles(): array {
        if (self::env_pro()) {
            return ['*'];
        }
        $granted = [];
        foreach (self::PRO_MODULES as $mod) {
            if (license_client::is_valid_for($mod['component'], $mod['slug'])) {
                $granted[] = $mod['slug'];
            }
        }
        return $granted;
    }

    /**
     * Convenience check consumed by bundle-aware Pro modules.
     *
     * @param string $bundle
     * @return bool
     */
    public static function has_bundle(string $bundle): bool {
        $b = self::bundles();
        return in_array('*', $b, true) || in_array($bundle, $b, true);
    }
}
