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
 * Token registry admin renderer.
 *
 * Renders the list of every access token the plugin ever issued and lets
 * an administrator revoke any of them. Lookups go through the registry
 * audit table (prefix only); revocation also flips the `revoked` flag in
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
 * Builds the token-list admin view and executes revoke actions.
 */
class token_list {

    /** @var int Maximum rows displayed per page. */
    public const PAGE_SIZE = 100;

    /**
     * Build the SQL WHERE clause for filter parameters.
     *
     * @param int|null    $userid   Filter by Moodle user id.
     * @param string|null $clientid Filter by OAuth client id.
     * @param string|null $status   active|revoked|expired (case-insensitive).
     * @return array{0:string,1:array<string,mixed>} [where, params].
     */
    private static function build_filter(?int $userid, ?string $clientid, ?string $status): array {
        $where = [];
        $params = [];

        if ($userid !== null && $userid > 0) {
            $where[] = 'r.user_id = :userid';
            $params['userid'] = $userid;
        }
        if ($clientid !== null && $clientid !== '') {
            $where[] = 'r.client_id = :clientid';
            $params['clientid'] = $clientid;
        }

        $now = time();
        $status = strtolower((string) $status);
        if ($status === 'revoked') {
            $where[] = 'r.revoked_at IS NOT NULL';
        } else if ($status === 'expired') {
            $where[] = 'r.revoked_at IS NULL AND r.expires_at < :now';
            $params['now'] = $now;
        } else if ($status === 'active') {
            $where[] = 'r.revoked_at IS NULL AND r.expires_at >= :now';
            $params['now'] = $now;
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
        global $DB, $USER;

        $rec = $DB->get_record('local_aiconnect_token_registry', ['id' => $registryid]);
        if (!$rec || $rec->revoked_at !== null) {
            return false;
        }

        $now = time();
        $by = isset($USER->id) ? (int) $USER->id : 0;

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->set_field('local_aiconnect_token_registry', 'revoked_at', $now, ['id' => $rec->id]);
            $DB->set_field('local_aiconnect_token_registry', 'revoked_by', $by, ['id' => $rec->id]);

            $like = $DB->sql_like('token', ':prefix');
            $DB->execute(
                "UPDATE {local_aiconnect_tokens} SET revoked = 1 WHERE $like",
                ['prefix' => $rec->token_prefix . '%']
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            return false;
        }
        return true;
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
        $total = (int) $DB->count_records_sql($countsql, $params);

        $sql = "SELECT r.id, r.token_prefix, r.user_id, r.client_id, r.scope, r.issued_at,
                       r.expires_at, r.last_used_at, r.revoked_at, r.revoked_by, r.source, r.ip_address,
                       u.firstname, u.lastname, u.email
                  FROM {local_aiconnect_token_registry} r
             LEFT JOIN {user} u ON u.id = r.user_id
                       $wheresql
              ORDER BY r.id DESC";

        $offset = max(0, $page) * self::PAGE_SIZE;
        $rows = $DB->get_records_sql($sql, $params, $offset, self::PAGE_SIZE);

        $sesskey = sesskey();
        $baseurl = $CFG->wwwroot . '/local/aiconnect/admin/tokens.php';
        $now = time();

        $userf = $userid !== null ? (int) $userid : '';
        $clientf = $clientid !== null ? s($clientid) : '';
        $statusf = $status !== null ? s($status) : '';

        $html  = '<h2>' . get_string('admin_tokens_heading', 'local_aiconnect') . '</h2>';
        $html .= '<form method="GET" action="' . s($baseurl) . '" style="margin-bottom:12px">';
        $html .= '<label>user_id: <input type="number" name="userid" value="' . $userf . '"></label> ';
        $html .= '<label>client_id: <input type="text" name="clientid" value="' . $clientf . '"></label> ';
        $html .= '<label>status: <select name="status">';
        foreach (['' => 'all', 'active' => 'active', 'revoked' => 'revoked', 'expired' => 'expired'] as $v => $l) {
            $sel = ($statusf === $v) ? ' selected' : '';
            $html .= '<option value="' . s($v) . '"' . $sel . '>' . s($l) . '</option>';
        }
        $html .= '</select></label> <button type="submit">Filter</button></form>';

        $html .= '<p>Total: <strong>' . $total . '</strong> tokens</p>';
        $html .= '<table class="generaltable" style="width:100%"><thead><tr>'
            . '<th>id</th><th>prefix</th><th>user</th><th>client</th><th>scope</th>'
            . '<th>issued</th><th>expires</th><th>last_used</th><th>status</th>'
            . '<th>source</th><th>action</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $statuslabel = 'active';
            if ($row->revoked_at !== null) {
                $statuslabel = 'revoked';
            } else if ((int) $row->expires_at < $now) {
                $statuslabel = 'expired';
            }

            $username = $row->firstname !== null
                ? trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')) . ' (#' . (int) $row->user_id . ')'
                : '#' . (int) $row->user_id;

            $html .= '<tr>'
                . '<td>' . (int) $row->id . '</td>'
                . '<td><code>' . s($row->token_prefix) . '…</code></td>'
                . '<td>' . s($username) . '</td>'
                . '<td>' . s($row->client_id) . '</td>'
                . '<td>' . s($row->scope) . '</td>'
                . '<td>' . userdate($row->issued_at) . '</td>'
                . '<td>' . userdate($row->expires_at) . '</td>'
                . '<td>' . ($row->last_used_at ? userdate($row->last_used_at) : '—') . '</td>'
                . '<td>' . $statuslabel . '</td>'
                . '<td>' . s($row->source) . '</td>'
                . '<td>';

            if ($statuslabel === 'active') {
                $revokeurl = new moodle_url('/local/aiconnect/admin/tokens.php', [
                    'action'  => 'revoke',
                    'id'      => (int) $row->id,
                    'sesskey' => $sesskey,
                    'userid'  => $userf,
                    'clientid' => $clientf,
                    'status'  => $statusf,
                ]);
                $html .= '<a href="' . s($revokeurl->out(false)) . '">revoke</a>';
            } else {
                $html .= '—';
            }
            $html .= '</td></tr>';
        }

        $html .= '</tbody></table>';

        $totalpages = (int) ceil($total / self::PAGE_SIZE);
        if ($totalpages > 1) {
            $html .= '<div style="margin-top:12px">';
            for ($i = 0; $i < $totalpages; $i++) {
                $href = new moodle_url($baseurl, [
                    'userid' => $userf, 'clientid' => $clientf, 'status' => $statusf, 'page' => $i,
                ]);
                $label = ($i === $page) ? '<strong>' . ($i + 1) . '</strong>' : ($i + 1);
                $html .= ' <a href="' . s($href->out(false)) . '">' . $label . '</a>';
            }
            $html .= '</div>';
        }

        return $html;
    }
}
