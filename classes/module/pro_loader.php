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
 * Edition-gated loader for the optional Pro tool-set plugin.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Discovers and registers the separate Pro plugin's tool module.
 *
 * Mirrors the WordPress/XenForo/Ghost model: the Pro tools live in a separate
 * plugin (local_aiconnectteacher) and are loaded into the core registry only
 * when the site edition grants Pro AND that plugin is installed. If either is
 * false, no Pro tools are registered — they never appear in the manifest and
 * cannot be executed.
 */
class pro_loader {

    /** @var string Fully-qualified class of the separate Pro plugin's module. */
    private const PRO_MODULE_CLASS = '\\local_aiconnectteacher\\module\\pro_module';

    /**
     * Register the Pro module into the given registry when the edition allows.
     *
     * @param module_registry $registry Registry being built.
     * @return bool True if the Pro module was registered, false if gated out.
     */
    public static function load(module_registry $registry): bool {
        if (self::edition() !== 'pro') {
            return false;
        }
        if (!class_exists(self::PRO_MODULE_CLASS)) {
            return false;
        }
        $class = self::PRO_MODULE_CLASS;
        $registry->register(new $class());
        return true;
    }

    /**
     * Resolve the configured edition.
     *
     * Reads the AICONNECT_EDITION environment variable first (parity with the
     * other platform bridges), then the plugin config, defaulting to 'free'.
     *
     * @return string Lowercased edition string ('free' or 'pro').
     */
    private static function edition(): string {
        $env = getenv('AICONNECT_EDITION');
        if (is_string($env) && $env !== '') {
            return strtolower($env);
        }
        $cfg = get_config('local_aiconnect', 'edition');
        return is_string($cfg) && $cfg !== '' ? strtolower($cfg) : 'free';
    }
}
