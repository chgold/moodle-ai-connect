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
 * Token registry admin renderer — v2.0.
 *
 * Renders the list of every access token the plugin ever issued and lets
 * an administrator revoke any of them individually or in bulk. Lookups go
 * through the registry audit table (prefix only); revocation cascades to
 * {local_aiconnect_tokens} so future Bearer calls are rejected.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\admin;

use local_aiconnect\service\token_registry;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the token-list admin view and executes revoke / bulk-revoke actions.
 */
class token_list {

    /** @var int Maximum rows displayed per page. */
    public const PAGE_SIZE = 100;

    /**
     * Build the SQL WHERE clause for filter parameters.
     *
     * @param int|null    $userid   Filter by Moodle user id.
     * @param string|null $clientid Filter by OAuth client id.
     * @param string|null $status   active|revoked|expired|unused|inactive (case-insensitive).
     * @return array{0:string,1:array<string,mixed>} [where, params].
     */
    private static function build_filter(?int $userid, ?string $clientid, ?string $status): array {
        $where  = [];
        $params = [];

        if ($userid !== null && $userid > 0) {
            $where[]          = 'r.user_id = :userid';
            $params['userid'] = $userid;
        }
        if ($clientid !== null && $clientid !== '') {
            $where[]            = 'r.client_id = :clientid';
            $params['clientid'] = $clientid;
        }

        $now    = time();
        $status = strtolower((string) $status);

        switch ($status) {
            case 'revoked':
                $where[] = 'r.revoked_at IS NOT NULL';
                break;
            case 'expired':
                $where[]        = 'r.revoked_at IS NULL AND r.expires_at < :now';
                $params['now']  = $now;
                break;
            case 'active':
                $where[]        = 'r.revoked_at IS NULL AND r.expires_at >= :now';
                $params['now']  = $now;
                break;
            case 'unused':
                $where[] = 'r.revoked_at IS NULL AND r.last_used_at IS NULL
                            AND r.last_used_ip IS NULL AND r.last_used_ua IS NULL';
                break;
            case 'inactive':
                $cutoff              = $now - (30 * DAYSECS);
                $where[]             = 'r.revoked_at IS NULL AND r.last_used_at IS NOT NULL AND r.last_used_at < :cutoff';
                $params['cutoff']    = $cutoff;
                break;
            default:
                break; // 'all' — no extra filter.
        }

        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return [$wheresql, $params];
    }

    /**
     * Revoke a single token by registry id.
     *
     * @param int $registryid Row id in {local_aiconnect_token_registry}.
     * @return bool True if a token was revoked.
     */
    public static function revoke_by_id(int $registryid): bool {
        global $USER;
        return token_registry::revoke_by_id($registryid, isset($USER->id) ? (int) $USER->id : null);
    }

    /**
     * Revoke all unused tokens (site-wide, not per-user).
     *
     * @return int Number of tokens revoked.
     */
    public static function revoke_bulk_unused(): int {
        global $DB, $USER;

        $prefixes = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'token_prefix',
            'revoked_at IS NULL AND last_used_at IS NULL AND last_used_ip IS NULL AND last_used_ua IS NULL'
        );
        if (empty($prefixes)) {
            return 0;
        }

        $ids = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'id',
            'revoked_at IS NULL AND last_used_at IS NULL AND last_used_ip IS NULL AND last_used_ua IS NULL'
        );
        return token_registry::revoke_by_ids(array_map('intval', $ids), isset($USER->id) ? (int) $USER->id : null);
    }

    /**
     * Revoke all inactive tokens (site-wide, not per-user).
     *
     * @return int Number of tokens revoked.
     */
    public static function revoke_bulk_inactive(): int {
        global $DB, $USER;

        $cutoff = time() - (30 * DAYSECS);
        $ids = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'id',
            'revoked_at IS NULL AND last_used_at IS NOT NULL AND last_used_at < :cutoff',
            ['cutoff' => $cutoff]
        );
        if (empty($ids)) {
            return 0;
        }
        return token_registry::revoke_by_ids(array_map('intval', $ids), isset($USER->id) ? (int) $USER->id : null);
    }

    /**
     * Revoke ALL active tokens site-wide (DANGER operation).
     *
     * @return int Number of tokens revoked.
     */
    public static function revoke_bulk_all(): int {
        global $DB, $USER;

        $ids = $DB->get_fieldset_select(
            'local_aiconnect_token_registry',
            'id',
            'revoked_at IS NULL AND expires_at > :now',
            ['now' => time()]
        );
        if (empty($ids)) {
            return 0;
        }
        return token_registry::revoke_by_ids(array_map('intval', $ids), isset($USER->id) ? (int) $USER->id : null);
    }

    /**
     * Compute the display state badge for a registry row.
     *
     * @param \stdClass $row    Registry row.
     * @param int       $now    Current unix timestamp.
     * @param int       $incutoff Inactive cutoff timestamp (now - 30d).
     * @return string           HTML badge string.
     */
    private static function state_badge(\stdClass $row, int $now, int $incutoff): string {
        if ($row->revoked_at !== null) {
            return '<span class="badge badge-danger">🔴 revoked</span>';
        }
        if ((int) $row->expires_at < $now) {
            return '<span class="badge badge-dark">⚫ expired</span>';
        }
        if ($row->last_used_at !== null && (int) $row->last_used_at < $incutoff) {
            return '<span class="badge badge-warning">🟠 inactive</span>';
        }
        if ($row->last_used_at === null) {
            return '<span class="badge badge-secondary">🟡 unused</span>';
        }
        return '<span class="badge badge-success">🟢 active</span>';
    }

    /**
     * Render the token-list page HTML.
     *
     * @param int|null    $userid   Filter by user id.
     * @param string|null $clientid Filter by client id.
     * @param string|null $status   Filter by status.
     * @param int         $page     Zero-based page index.
     * @return string HTML.
     */
    public static function render(?int $userid, ?string $clientid, ?string $status, int $page = 0): string {
        global $DB, $CFG, $OUTPUT;

        [$wheresql, $params] = self::build_filter($userid, $clientid, $status);

        $countsql = "SELECT COUNT(1) FROM {local_aiconnect_token_registry} r $wheresql";
        $total    = (int) $DB->count_records_sql($countsql, $params);

        $sql = "SELECT r.id, r.token_prefix, r.user_id, r.client_id, r.scope, r.issued_at,
                       r.expires_at, r.last_used_at, r.last_used_ip, r.last_used_ua,
                       r.revoked_at, r.revoked_by, r.source, r.ip_address,
                       u.firstname, u.lastname, u.email
                  FROM {local_aiconnect_token_registry} r
             LEFT JOIN {user} u ON u.id = r.user_id
                       $wheresql
              ORDER BY r.id DESC";

        $offset = max(0, $page) * self::PAGE_SIZE;
        $rows   = $DB->get_records_sql($sql, $params, $offset, self::PAGE_SIZE);

        $sesskey = sesskey();
        $baseurl = $CFG->wwwroot . '/local/aiconnect/admin/tokens.php';
        $now     = time();
        $incutoff = $now - (30 * DAYSECS);

        $userf   = $userid !== null ? (int) $userid : '';
        $clientf = $clientid !== null ? s($clientid) : '';
        $statusf = $status !== null ? s($status) : '';

        $html  = '<h2>' . get_string('admin_tokens_heading', 'local_aiconnect') . '</h2>';

        // ── Filter form ────────────────────────────────────────────────────────
        $html .= '<form method="GET" action="' . s($baseurl) . '" style="margin-bottom:12px">';
        $html .= '<label>user_id: <input type="number" name="userid" value="' . $userf . '"></label> ';
        $html .= '<label>client_id: <input type="text" name="clientid" value="' . $clientf . '"></label> ';
        $html .= '<label>status: <select name="status">';
        $statusoptions = [
            ''         => 'all',
            'active'   => 'active',
            'unused'   => 'unused',
            'inactive' => 'inactive',
            'revoked'  => 'revoked',
            'expired'  => 'expired',
        ];
        foreach ($statusoptions as $v => $l) {
            $sel   = ($statusf === $v) ? ' selected' : '';
            $html .= '<option value="' . s($v) . '"' . $sel . '>' . s($l) . '</option>';
        }
        $html .= '</select></label> <button type="submit">Filter</button></form>';

        // ── Bulk actions ────────────────────────────────────────────────────────
        $html .= '<form method="POST" action="' . s($baseurl) . '" style="margin-bottom:12px">';
        $html .= '<input type="hidden" name="sesskey" value="' . s($sesskey) . '">';
        $html .= '<input type="hidden" name="userid" value="' . $userf . '">';
        $html .= '<input type="hidden" name="clientid" value="' . $clientf . '">';
        $html .= '<input type="hidden" name="status" value="' . $statusf . '">';
        $html .= '<input type="hidden" name="page" value="' . (int) $page . '">';
        $html .= '<button type="submit" name="bulk_action" value="revoke_unused"'
            . ' onclick="return confirm(\'Revoke all unused tokens? This cannot be undone.\')"'
            . ' class="btn btn-sm btn-warning mr-1">'
            . s(get_string('admin_tokens_bulk_unused', 'local_aiconnect')) . '</button>';
        $html .= '<button type="submit" name="bulk_action" value="revoke_inactive"'
            . ' onclick="return confirm(\'Revoke all inactive tokens? This cannot be undone.\')"'
            . ' class="btn btn-sm btn-warning mr-1">'
            . s(get_string('admin_tokens_bulk_inactive', 'local_aiconnect')) . '</button>';
        $html .= '<button type="submit" name="bulk_action" value="revoke_all"'
            . ' onclick="return confirm(\'DANGER: Revoke ALL active tokens? This cannot be undone.\') && confirm(\'Are you absolutely sure?\')"'
            . ' class="btn btn-sm btn-danger mr-1">'
            . s(get_string('admin_tokens_bulk_all', 'local_aiconnect')) . '</button>';
        $html .= '</form>';

        // ── Count ───────────────────────────────────────────────────────────────
        $html .= '<p>Total: <strong>' . $total . '</strong> tokens</p>';

        // ── Table ───────────────────────────────────────────────────────────────
        $html .= '<table class="generaltable" style="width:100%"><thead><tr>'
            . '<th>id</th><th>prefix</th><th>user</th><th>client</th><th>scope</th>'
            . '<th>issued</th><th>expires</th><th>last_used</th>'
            . '<th>' . get_string('admin_tokens_col_lastip', 'local_aiconnect') . '</th>'
            . '<th>' . get_string('admin_tokens_col_lastua', 'local_aiconnect') . '</th>'
            . '<th>state</th><th>source</th><th>action</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $statebadge = self::state_badge($row, $now, $incutoff);

            $username = $row->firstname !== null
                ? trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')) . ' (#' . (int) $row->user_id . ')'
                : '#' . (int) $row->user_id;

            $lastused = $row->last_used_at ? userdate((int) $row->last_used_at) : '—';

            $ua = (string) ($row->last_used_ua ?? '');
            if (strlen($ua) > 30) {
                $ua = substr($ua, 0, 27) . '…';
            }

            $isactive = ($row->revoked_at === null && (int) $row->expires_at >= $now);
            if ($isactive) {
                $revokeurl = new moodle_url('/local/aiconnect/admin/tokens.php', [
                    'action'   => 'revoke',
                    'id'       => (int) $row->id,
                    'sesskey'  => $sesskey,
                    'userid'   => $userf,
                    'clientid' => $clientf,
                    'status'   => $statusf,
                ]);
                $action = '<a href="' . s($revokeurl->out(false)) . '" class="btn btn-xs btn-danger">revoke</a>';
            } else {
                $action = '—';
            }

            $html .= '<tr>'
                . '<td>' . (int) $row->id . '</td>'
                . '<td><code>' . s($row->token_prefix) . '…</code></td>'
                . '<td>' . s($username) . '</td>'
                . '<td>' . s($row->client_id) . '</td>'
                . '<td>' . s($row->scope) . '</td>'
                . '<td>' . userdate((int) $row->issued_at) . '</td>'
                . '<td>' . userdate((int) $row->expires_at) . '</td>'
                . '<td>' . $lastused . '</td>'
                . '<td>' . s((string) ($row->last_used_ip ?? '—')) . '</td>'
                . '<td title="' . s($row->last_used_ua ?? '') . '">' . s($ua ?: '—') . '</td>'
                . '<td>' . $statebadge . '</td>'
                . '<td>' . s($row->source) . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        // ── Pagination ──────────────────────────────────────────────────────────
        $totalpages = (int) ceil($total / self::PAGE_SIZE);
        if ($totalpages > 1) {
            $html .= '<div style="margin-top:12px">';
            for ($i = 0; $i < $totalpages; $i++) {
                $href  = new moodle_url($baseurl, [
                    'userid'   => $userf,
                    'clientid' => $clientf,
                    'status'   => $statusf,
                    'page'     => $i,
                ]);
                $label = ($i === $page) ? '<strong>' . ($i + 1) . '</strong>' : ($i + 1);
                $html .= ' <a href="' . s($href->out(false)) . '">' . $label . '</a>';
            }
            $html .= '</div>';
        }

        return $html;
    }
}
