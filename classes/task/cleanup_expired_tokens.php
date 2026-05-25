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
 * Scheduled task that purges old tokens, authorization codes and rate limits.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\task;

use core\task\scheduled_task;
use local_aiconnect\service\token_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily cleanup of expired OAuth state and token registry.
 *
 * Removes / revokes:
 *  - access tokens whose `expiry` is older than the grace window (7 days).
 *  - all consumed or expired authorization codes.
 *  - rate-limit rows that belong to stale windows.
 *  - token registry rows per 4-rule lifecycle (unused 30d, inactive 180d,
 *    expired 90d, hard-delete revoked 365d).
 */
class cleanup_expired_tokens extends scheduled_task {

    /** @var int Grace window in seconds before deleting expired tokens (7 days). */
    private const GRACE_SECONDS = 7 * DAYSECS;

    /**
     * Human readable name shown in Site administration > Server > Scheduled tasks.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_expired', 'local_aiconnect');
    }

    /**
     * Run the cleanup.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $cutoff = $now - self::GRACE_SECONDS;

        // Phase 1: clean OAuth tokens, codes, and rate-limit rows.
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records_select('local_aiconnect_tokens', 'expiry < :cutoff', ['cutoff' => $cutoff]);
            $DB->delete_records_select('local_aiconnect_codes', 'used = 1 OR expiry < :now', ['now' => $now]);
            $DB->delete_records_select('local_aiconnect_ratelimits',
                'windowstart < :cutoff', ['cutoff' => $now - HOURSECS]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
                mtrace('local_aiconnect cleanup (phase 1) failed: ' . $e->getMessage());
            }
        }

        // Phase 2: token registry lifecycle cleanup (4-rule).
        $summary = token_registry::run_cleanup();

        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            mtrace(sprintf(
                'local_aiconnect: cleanup_expired_tokens complete. Registry: unused=%d inactive=%d expired=%d deleted=%d',
                $summary['unused'], $summary['inactive'], $summary['expired'], $summary['deleted']
            ));
        }
    }
}
