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
 * Token registry service v2.0 — audit trail of every issued access token.
 *
 * The registry stores only the first 16 characters of each token so an
 * attacker who reads the registry table cannot use the values to call
 * the API. The prefix is enough to correlate audit events with the
 * working token row in {local_aiconnect_tokens}.
 *
 * Every revoke method cascades to {local_aiconnect_tokens} via
 * cascade_revoke_oauth() so that refresh attempts on revoked tokens fail.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helpers for writing/reading the token registry audit table.
 *
 * Token secrets are never stored here — only the first 16 characters of the
 * access token (token_prefix) are kept for fast indexed lookups.
 */
class token_registry {

    /** @var int Number of leading characters of a token to persist. */
    public const PREFIX_LENGTH = 16;

    /** @var string Config key storing the last cleanup timestamp. */
    private const LAST_CLEANUP_KEY = 'token_registry_last_cleanup';

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
     * @param string   $token             Full access token.
     * @param int      $userid            Moodle user id the token authenticates as.
     * @param string   $clientid          OAuth client identifier.
     * @param string   $scope             Granted scopes.
     * @param int      $expiresat         Unix timestamp when the token expires.
     * @param string   $source            How the token was issued (oauth|generator|refresh).
     * @param int|null $refreshexpiresat  Optional refresh token expiration timestamp.
     * @return void
     */
    public static function register(string $token, int $userid, string $clientid,
            string $scope, int $expiresat, string $source = 'oauth',
            ?int $refreshexpiresat = null): void {
        global $DB;

        $record = (object) [
            'token_prefix'    => self::prefix($token),
            'user_id'         => $userid,
            'client_id'       => $clientid !== '' ? $clientid : 'unknown',
            'scope'           => $scope,
            'issued_at'       => time(),
            'expires_at'      => $expiresat,
            'last_used_at'    => null,
            'last_used_ip'    => null,
            'last_used_ua'    => null,
            'revoked_at'      => null,
            'revoked_by'      => null,
            'source'          => self::normalize_source($source),
            'ip_address'      => substr((string) (getremoteaddr() ?: ''), 0, 45),
        ];

        if ($refreshexpiresat !== null) {
            $record->refresh_expires_at = $refreshexpiresat;
        }

        $DB->insert_record('local_aiconnect_token_registry', $record);
    }

    /**
     * Update last_used_at, last_used_ip, and last_used_ua for a token on a
     * successful API call. Replaces the old touch() method.
     *
     * Failure here NEVER blocks the request (best-effort, exceptions swallowed).
     *
     * @param string      $token Full access token.
     * @param string|null $ip    Client IP from the authenticated request.
     * @param string|null $ua    User-Agent string from the authenticated request.
     * @return void
     */
    public static function touch_last_used(string $token, ?string $ip = null, ?string $ua = null): void {
        global $DB;

        $prefix = self::prefix($token);
        if ($prefix === '') {
            return;
        }

        try {
            $rec = $DB->get_record_select(
                'local_aiconnect_token_registry',
                'token_prefix = :p AND revoked_at IS NULL',
                ['p' => $prefix],
                'id',
                IGNORE_MULTIPLE
            );
            if (!$rec) {
                return;
            }

            $fields = ['last_used_at' => time()];
            if ($ip !== null) {
                $fields['last_used_ip'] = substr((string) $ip, 0, 45);
            }
            if ($ua !== null) {
                $fields['last_used_ua'] = substr((string) $ua, 0, 255);
            }

            foreach ($fields as $field => $value) {
                $DB->set_field('local_aiconnect_token_registry', $field, $value, ['id' => $rec->id]);
            }
        } catch (\Throwable $e) {
            // Best-effort: never let registry errors block the API response.
            debugging('local_aiconnect token_registry::touch_last_used failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Update the last_used_at timestamp for a token on a successful API call.
     *
     * @deprecated since 2.0.0 — use touch_last_used($token, $ip, $ua) instead.
     * @param string $token Full access token.
     * @return void
     */
    public static function touch(string $token): void {
        self::touch_last_used($token);
    }

    /**
     * Mark a token (by full string) as revoked. Cascades to the tokens table.
     *
     * @param string   $token     Full access token being revoked.
     * @param int|null $revokedby Moodle user id performing the revoke (null = current user).
     * @return void
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
            self::cascade_revoke_oauth([$prefix], $now);
        }
    }

    /**
     * Revoke a single registry row by its primary key ID.
     *
     * @param int      $id        Registry row id.
     * @param int|null $revokedby User id performing the revoke; null = current user.
     * @return bool True when a row was actually revoked.
     */
    public static function revoke_by_id(int $id, ?int $revokedby = null): bool {
        global $DB, $USER;

        $rec = $DB->get_record_select(
            'local_aiconnect_token_registry',
            'id = :id AND revoked_at IS NULL',
            ['id' => $id],
            'id, token_prefix'
        );
        if (!$rec) {
            return false;
        }

        $now = time();
        $by = $revokedby ?? (isset($USER->id) ? (int) $USER->id : 0);

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->set_field('local_aiconnect_token_registry', 'revoked_at', $now, ['id' => $rec->id]);
            $DB->set_field('local_aiconnect_token_registry', 'revoked_by', $by, ['id' => $rec->id]);
            self::cascade_revoke_oauth([$rec->token_prefix], $now);
            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            return false;
        }
    }

    /**
     * Revoke all active tokens for a given user. Cascades to the tokens table.
     *
     * @param int      $userid    Moodle user id whose tokens are revoked.
     * @param int|null $revokedby User id performing the revoke; null = current user.
     * @return int Number of registry rows revoked.
     */
    public static function revoke_all_for_user(int $userid, ?int $revokedby = null): int {
        global $DB, $USER;

        $prefixes = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'token_prefix',
            'user_id = :uid AND revoked_at IS NULL',
            ['uid' => $userid]
        );
        if (empty($prefixes)) {
            return 0;
        }

        $now = time();
        $by = $revokedby ?? (isset($USER->id) ? (int) $USER->id : 0);

        $transaction = $DB->start_delegated_transaction();
        try {
            [$insql, $inparams] = $DB->get_in_or_equal($prefixes, SQL_PARAMS_NAMED, 'p');
            $inparams['uid'] = $userid;
            $count = $DB->count_records_select(
                'local_aiconnect_token_registry',
                "user_id = :uid AND revoked_at IS NULL AND token_prefix $insql",
                $inparams
            );
            $DB->execute(
                "UPDATE {local_aiconnect_token_registry}
                    SET revoked_at = :now, revoked_by = :by
                  WHERE user_id = :uid AND revoked_at IS NULL",
                ['now' => $now, 'by' => $by, 'uid' => $userid]
            );
            self::cascade_revoke_oauth($prefixes, $now);
            $transaction->allow_commit();
            return (int) $count;
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            return 0;
        }
    }

    /**
     * Revoke an array of registry rows by their primary key IDs.
     *
     * @param int[]    $ids       Array of registry row IDs.
     * @param int|null $revokedby User id performing the revoke; null = current user.
     * @return int Number of registry rows revoked.
     */
    public static function revoke_by_ids(array $ids, ?int $revokedby = null): int {
        global $DB, $USER;

        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'id');

        $prefixes = $DB->get_fieldset_sql(
            "SELECT token_prefix FROM {local_aiconnect_token_registry}
              WHERE id $insql AND revoked_at IS NULL",
            $inparams
        );
        if (empty($prefixes)) {
            return 0;
        }

        $now = time();
        $by = $revokedby ?? (isset($USER->id) ? (int) $USER->id : 0);

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->execute(
                "UPDATE {local_aiconnect_token_registry}
                    SET revoked_at = :now, revoked_by = :by
                  WHERE id $insql AND revoked_at IS NULL",
                array_merge(['now' => $now, 'by' => $by], $inparams)
            );
            self::cascade_revoke_oauth($prefixes, $now);
            $transaction->allow_commit();
            return count($prefixes);
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            return 0;
        }
    }

    /**
     * Revoke tokens that were never used after a given number of days.
     *
     * Targets rows where last_used_at, last_used_ip, and last_used_ua are all
     * NULL and the token was issued more than $days days ago.
     *
     * @param int      $days      Minimum age (days) before an unused token is revoked. Default 30.
     * @param int|null $revokedby User id performing the revoke; null = system.
     * @return int Number of tokens revoked.
     */
    public static function revoke_unused(int $days = 30, ?int $revokedby = null): int {
        global $DB;

        $cutoff = time() - ($days * DAYSECS);

        $prefixes = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'token_prefix',
            'revoked_at IS NULL AND last_used_at IS NULL AND last_used_ip IS NULL
             AND last_used_ua IS NULL AND issued_at < :cutoff',
            ['cutoff' => $cutoff]
        );
        if (empty($prefixes)) {
            return 0;
        }

        $now = time();
        $by = $revokedby;

        $transaction = $DB->start_delegated_transaction();
        try {
            [$insql, $inparams] = $DB->get_in_or_equal($prefixes, SQL_PARAMS_NAMED, 'p');
            $inparams['now'] = $now;
            $inparams['by'] = $by;
            $DB->execute(
                "UPDATE {local_aiconnect_token_registry}
                    SET revoked_at = :now, revoked_by = :by
                  WHERE token_prefix $insql AND revoked_at IS NULL",
                $inparams
            );
            self::cascade_revoke_oauth($prefixes, $now);
            $transaction->allow_commit();
            return count($prefixes);
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            return 0;
        }
    }

    /**
     * Revoke tokens that have not been used within the last $days days.
     *
     * Only targets tokens that have been used at least once (last_used_at IS NOT
     * NULL) but whose last activity is older than $days days. Truly unused tokens
     * are handled separately by revoke_unused().
     *
     * @param int      $days      Inactivity threshold in days. Default 180.
     * @param int|null $revokedby User id performing the revoke; null = system.
     * @return int Number of tokens revoked.
     */
    public static function revoke_inactive(int $days = 180, ?int $revokedby = null): int {
        global $DB;

        $cutoff = time() - ($days * DAYSECS);

        $prefixes = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'token_prefix',
            'revoked_at IS NULL AND last_used_at IS NOT NULL AND last_used_at < :cutoff',
            ['cutoff' => $cutoff]
        );
        if (empty($prefixes)) {
            return 0;
        }

        $now = time();
        $by = $revokedby;

        $transaction = $DB->start_delegated_transaction();
        try {
            [$insql, $inparams] = $DB->get_in_or_equal($prefixes, SQL_PARAMS_NAMED, 'p');
            $inparams['now'] = $now;
            $inparams['by'] = $by;
            $DB->execute(
                "UPDATE {local_aiconnect_token_registry}
                    SET revoked_at = :now, revoked_by = :by
                  WHERE token_prefix $insql AND revoked_at IS NULL",
                $inparams
            );
            self::cascade_revoke_oauth($prefixes, $now);
            $transaction->allow_commit();
            return count($prefixes);
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            return 0;
        }
    }

    /**
     * Returns true if the cleanup job should run (not run in the last 24 hours).
     *
     * Uses Moodle plugin config to persist the last-run timestamp.
     *
     * @return bool True if more than 24 hours have elapsed since the last cleanup.
     */
    public static function should_run_cleanup(): bool {
        $last = (int) get_config('local_aiconnect', self::LAST_CLEANUP_KEY);
        return (time() - $last) > DAYSECS;
    }

    /**
     * Run the four-rule token cleanup and log a summary via mtrace/debugging.
     *
     * Rules applied in order:
     *   1. Revoke never-used tokens older than 30 days.
     *   2. Revoke tokens with no activity in 180+ days.
     *   3. Soft-revoke expired tokens that are 90+ days past their expiry.
     *   4. Hard-delete revoked rows that are 365+ days old.
     *
     * @return array{unused:int,inactive:int,expired:int,deleted:int} Counts per rule.
     */
    public static function run_cleanup(): array {
        global $DB;

        $summary = ['unused' => 0, 'inactive' => 0, 'expired' => 0, 'deleted' => 0];
        $now = time();

        try {
            // Rule 1: never-used tokens older than 30 days.
            $summary['unused'] = self::revoke_unused(30, null);

            // Rule 2: tokens inactive for 180+ days.
            $summary['inactive'] = self::revoke_inactive(180, null);

            // Rule 3: expired tokens 90+ days past their expiry.
            $expiredcutoff = $now - (90 * DAYSECS);
            $expiredprefixes = $DB->get_fieldset_select(
                'local_aiconnect_token_registry',
                'token_prefix',
                'revoked_at IS NULL AND expires_at < :cutoff',
                ['cutoff' => $expiredcutoff]
            );
            if (!empty($expiredprefixes)) {
                [$insql, $inparams] = $DB->get_in_or_equal($expiredprefixes, SQL_PARAMS_NAMED, 'p');
                $inparams['now'] = $now;
                $DB->execute(
                    "UPDATE {local_aiconnect_token_registry}
                        SET revoked_at = :now, revoked_by = NULL
                      WHERE token_prefix $insql AND revoked_at IS NULL",
                    $inparams
                );
                self::cascade_revoke_oauth($expiredprefixes, $now);
                $summary['expired'] = count($expiredprefixes);
            }

            // Rule 4: hard-delete revoked rows 365+ days old.
            $deletecutoff = $now - (365 * DAYSECS);
            $summary['deleted'] = $DB->count_records_select(
                'local_aiconnect_token_registry',
                'revoked_at IS NOT NULL AND revoked_at < :cutoff',
                ['cutoff' => $deletecutoff]
            );
            if ($summary['deleted'] > 0) {
                $DB->delete_records_select(
                    'local_aiconnect_token_registry',
                    'revoked_at IS NOT NULL AND revoked_at < :cutoff',
                    ['cutoff' => $deletecutoff]
                );
            }

            set_config(self::LAST_CLEANUP_KEY, $now, 'local_aiconnect');

            if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
                mtrace(sprintf(
                    'local_aiconnect token_registry cleanup: unused=%d inactive=%d expired=%d deleted=%d',
                    $summary['unused'], $summary['inactive'], $summary['expired'], $summary['deleted']
                ));
            }
        } catch (\Throwable $e) {
            debugging('local_aiconnect token_registry::run_cleanup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $summary;
    }

    /**
     * Returns true if the user has at least one active (non-revoked, unexpired) token.
     *
     * @param int $userid Moodle user id.
     * @return bool True if the user has at least one active token.
     */
    public static function user_has_active_tokens(int $userid): bool {
        global $DB;

        return $DB->record_exists_select(
            'local_aiconnect_token_registry',
            'user_id = :uid AND revoked_at IS NULL AND expires_at > :now',
            ['uid' => $userid, 'now' => time()]
        );
    }

    /**
     * List tokens for a specific user with optional status filter.
     *
     * Supported filter values:
     *   all      — no filter (default)
     *   active   — not revoked, not expired
     *   unused   — never authenticated (all last_used_* NULL)
     *   inactive — used before but not in the last 30 days
     *   expired  — not revoked, but past expires_at
     *   revoked  — revoked_at IS NOT NULL
     *
     * @param int    $userid Moodle user id.
     * @param string $filter One of: all|active|unused|inactive|expired|revoked.
     * @return \stdClass[] Registry rows ordered by issued_at DESC, newest first.
     */
    public static function list_tokens_for_user(int $userid, string $filter = 'all'): array {
        global $DB;

        $now = time();
        $params = ['uid' => $userid];
        $extra = '';

        switch ($filter) {
            case 'active':
                $extra = 'AND revoked_at IS NULL AND expires_at > :now';
                $params['now'] = $now;
                break;
            case 'unused':
                $extra = 'AND revoked_at IS NULL AND last_used_at IS NULL
                          AND last_used_ip IS NULL AND last_used_ua IS NULL';
                break;
            case 'inactive':
                $cutoff = $now - (30 * DAYSECS);
                $extra = 'AND revoked_at IS NULL AND last_used_at IS NOT NULL AND last_used_at < :cutoff';
                $params['cutoff'] = $cutoff;
                break;
            case 'expired':
                $extra = 'AND revoked_at IS NULL AND expires_at <= :now';
                $params['now'] = $now;
                break;
            case 'revoked':
                $extra = 'AND revoked_at IS NOT NULL';
                break;
            case 'all':
            default:
                $extra = '';
                break;
        }

        $records = $DB->get_records_sql(
            "SELECT * FROM {local_aiconnect_token_registry}
              WHERE user_id = :uid $extra
           ORDER BY issued_at DESC",
            $params,
            0,
            200
        );

        return array_values($records);
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

    /**
     * Cascade token revocation to {local_aiconnect_tokens}.
     *
     * Sets revoked = 1 on rows whose token starts with one of the given
     * prefixes. Called by every revoke method after the registry UPDATE.
     *
     * @param string[] $prefixes Array of 16-character token prefixes.
     * @param int      $time     The revocation timestamp (unused; kept for signature compat).
     * @return void
     */
    public static function cascade_revoke_oauth(array $prefixes, int $time): void {
        global $DB;

        if (empty($prefixes) || !$DB->get_manager()->table_exists('local_aiconnect_tokens')) {
            return;
        }

        try {
            foreach ($prefixes as $prefix) {
                $like = $DB->sql_like('token', ':p');
                $DB->execute(
                    "UPDATE {local_aiconnect_tokens} SET revoked = 1 WHERE $like AND revoked = 0",
                    ['p' => $DB->sql_like_escape($prefix) . '%']
                );
            }
        } catch (\Throwable $e) {
            debugging('local_aiconnect token_registry::cascade_revoke_oauth failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Normalize a source string to one of the allowed values.
     *
     * @param string $source Raw source value.
     * @return string One of: generator, oauth, refresh.
     */
    private static function normalize_source(string $source): string {
        $source = strtolower(trim($source));
        if (!in_array($source, ['generator', 'oauth', 'refresh'], true)) {
            $source = 'oauth';
        }
        return $source;
    }
}
