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
 * OAuth2 authorization-code + PKCE controller.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\controller;

use local_aiconnect\lib\http;
use local_aiconnect\service\token_registry;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Implements OAuth2 authorize / token / revoke endpoints.
 *
 * All persistence happens inside delegated transactions so a partial failure
 * (e.g. mid-issue crash) never leaves an authorization code marked used
 * without the matching tokens being written.
 */
class oauth_controller {
    /** @var int Access token lifetime in seconds. */
    private const ACCESS_TOKEN_TTL = 3600;

    /** @var int Refresh token lifetime in seconds (30 days). */
    private const REFRESH_TOKEN_TTL = 2592000;

    /** @var int Authorization code lifetime in seconds. */
    private const AUTH_CODE_TTL = 600;

    /**
     * Verify a PKCE code_verifier against the stored S256 challenge.
     *
     * The challenge is the base64url-encoded SHA-256 of the verifier as
     * defined by RFC 7636 §4.6. We pad it back to base64 before comparing.
     *
     * @param string $verifier  Raw verifier provided in the token request.
     * @param string $challenge Stored code_challenge.
     * @return bool True when the verifier matches.
     */
    private function verify_pkce(string $verifier, string $challenge): bool {
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($expected, $challenge);
    }

    /**
     * Cryptographically random opaque token.
     *
     * @return string 64-character hex string (32 bytes of entropy).
     */
    private function make_token(): string {
        return bin2hex(random_bytes(32));
    }

    private static function normalize_client_id(string $clientid): string {
        $lower = strtolower($clientid);
        $stripped = preg_replace('/[-_]ai$/', '', $lower);
        return preg_replace('/[-_]/', '', $stripped);
    }

    private function find_client(string $clientid): ?\stdClass {
        global $DB;
        $exact = $DB->get_record('local_aiconnect_clients', ['client_id' => $clientid]);
        if ($exact) {
            return $exact;
        }
        $normalized = self::normalize_client_id($clientid);
        if ($normalized === '') {
            return null;
        }
        $all = $DB->get_records('local_aiconnect_clients');
        foreach ($all as $row) {
            if (self::normalize_client_id($row->client_id) === $normalized) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Handle GET (consent screen) and POST (approval) on the authorize endpoint.
     */
    public function authorize(): void {
        global $USER, $DB;

        require_login(null, false);

        $clientid    = required_param('client_id', PARAM_ALPHANUMEXT);
        $challenge   = required_param('code_challenge', PARAM_RAW_TRIMMED);
        $method      = optional_param('code_challenge_method', 'S256', PARAM_ALPHA);
        $redirecturi = optional_param('redirect_uri', '', PARAM_URL);
        $scope       = optional_param('scope', 'read write', PARAM_RAW_TRIMMED);
        $state       = optional_param('state', '', PARAM_RAW_TRIMMED);
        $approved    = optional_param('approved', '', PARAM_ALPHA);

        if ($method !== 'S256') {
            http::oauth_error(400, 'invalid_request', 'Only S256 code_challenge_method is supported');
        }

        $matched = $this->find_client($clientid);
        if (!$matched) {
            http::oauth_error(400, 'invalid_client');
        }
        $clientid = $matched->client_id;

        if (!http::is_redirect_uri_allowed($redirecturi)) {
            http::oauth_error(400, 'invalid_request', 'redirect_uri must use https');
        }

        $context = \context_system::instance();
        if (!has_capability('local/aiconnect:use', $context)) {
            http::oauth_error(403, 'access_denied', 'User lacks local/aiconnect:use capability');
        }

        if ($approved === 'true') {
            require_sesskey();

            $code = $this->make_token();
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->insert_record('local_aiconnect_codes', (object) [
                    'code'           => $code,
                    'client_id'      => $clientid,
                    'userid'         => (int) $USER->id,
                    'scope'          => $scope,
                    'code_challenge' => $challenge,
                    'redirect_uri'   => $redirecturi,
                    'expiry'         => time() + self::AUTH_CODE_TTL,
                    'used'           => 0,
                ]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            }

            $dest = ($redirecturi !== '' && $redirecturi !== 'urn:ietf:wg:oauth:2.0:oob')
                ? $redirecturi . (str_contains($redirecturi, '?') ? '&' : '?') . http_build_query(['code' => $code, 'state' => $state])
                : new moodle_url('/local/aiconnect/oauth.php', [
                    'action' => 'callback', 'code' => $code, 'state' => $state,
                ]);
            redirect($dest);
            return;
        }

        $client = $matched;
        echo $this->render_consent($client->client_name, $scope, $clientid, $challenge, $redirecturi, $state);
    }

    /**
     * Render the user-facing consent screen.
     *
     * @param string $name        Display name of the requesting client.
     * @param string $scope       Requested scopes.
     * @param string $clientid    Client identifier.
     * @param string $challenge   PKCE code_challenge.
     * @param string $redirecturi Redirect URI to forward to on approval.
     * @param string $state       Opaque state from the client.
     * @return string Rendered HTML page.
     */
    private function render_consent(
        string $name,
        string $scope,
        string $clientid,
        string $challenge,
        string $redirecturi,
        string $state
    ): string {
        global $CFG;
        $sesskey = sesskey();
        $action = $CFG->wwwroot . '/local/aiconnect/oauth.php?action=authorize';
        $deny = $CFG->wwwroot . '/local/aiconnect/oauth.php?action=deny';
        $sname = s($name);
        $sscope = s($scope);
        $scid = s($clientid);
        $sch = s($challenge);
        $sru = s($redirecturi);
        $sst = s($state);
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><title>AI Connect Authorization</title>
<style>body{font-family:sans-serif;max-width:500px;margin:80px auto;padding:20px}
.btn{padding:10px 20px;border:none;border-radius:4px;cursor:pointer;font-size:15px}
.approve{background:#4CAF50;color:#fff}.deny{background:#f44336;color:#fff;margin-left:8px}</style></head><body>
<h2>AI Connect Authorization</h2>
<p>The agent <strong>$sname</strong> is requesting access.</p>
<p>Scopes: <strong>$sscope</strong></p>
<form method="POST" action="$action">
<input type="hidden" name="sesskey" value="$sesskey">
<input type="hidden" name="client_id" value="$scid">
<input type="hidden" name="code_challenge" value="$sch">
<input type="hidden" name="redirect_uri" value="$sru">
<input type="hidden" name="scope" value="$sscope">
<input type="hidden" name="state" value="$sst">
<input type="hidden" name="approved" value="true">
<button type="submit" class="btn approve">Approve</button>
</form>
<form method="POST" action="$deny" style="display:inline">
<input type="hidden" name="sesskey" value="$sesskey">
<button type="submit" class="btn deny">Deny</button>
</form></body></html>
HTML;
    }

    /**
     * Handle POST /token — authorization_code and refresh_token grants.
     */
    public function token(): void {
        global $DB;

        // Support both application/x-www-form-urlencoded and application/json.
        $contenttype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($contenttype, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $body = json_decode((string) $raw, true);
            if (is_array($body)) {
                foreach ($body as $k => $v) {
                    if (!isset($_POST[$k])) {
                        $_POST[$k] = is_scalar($v) ? (string) $v : '';
                    }
                }
            }
        }

        $grant = required_param('grant_type', PARAM_ALPHANUMEXT);

        if ($grant === 'authorization_code') {
            $code     = required_param('code', PARAM_RAW_TRIMMED);
            $verifier = required_param('code_verifier', PARAM_RAW_TRIMMED);

            $rec = $DB->get_record('local_aiconnect_codes', ['code' => $code]);
            if (!$rec || (int) $rec->used === 1 || time() > (int) $rec->expiry) {
                http::oauth_error(400, 'invalid_grant', 'Authorization code is invalid, used or expired');
            }
            if (!$this->verify_pkce($verifier, $rec->code_challenge)) {
                http::oauth_error(400, 'invalid_grant', 'PKCE verification failed');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->set_field('local_aiconnect_codes', 'used', 1, ['id' => $rec->id]);
                [$at, $rt] = $this->issue_tokens($rec->client_id, (int) $rec->userid, $rec->scope);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
                http::oauth_error(500, 'server_error', 'Failed to issue tokens');
            }

            echo json_encode([
                'access_token'             => $at,
                'token_type'               => 'Bearer',
                'expires_in'               => self::ACCESS_TOKEN_TTL,
                'refresh_token'            => $rt,
                'refresh_token_expires_in' => self::REFRESH_TOKEN_TTL,
                'scope'                    => $rec->scope,
            ]);
            return;
        }

        if ($grant === 'refresh_token') {
            $refreshtoken = required_param('refresh_token', PARAM_RAW_TRIMMED);
            $rec = $DB->get_record('local_aiconnect_tokens', [
                'token' => $refreshtoken, 'tokentype' => 'refresh', 'revoked' => 0,
            ]);
            if (!$rec || time() > (int) $rec->expiry) {
                http::oauth_error(400, 'invalid_grant', 'Refresh token is invalid or expired');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->set_field('local_aiconnect_tokens', 'revoked', 1, ['id' => $rec->id]);
                [$at, $newrt] = $this->issue_tokens($rec->client_id, (int) $rec->userid, $rec->scope, 'refresh');
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
                http::oauth_error(500, 'server_error', 'Failed to refresh token');
            }

            echo json_encode([
                'access_token'  => $at,
                'token_type'    => 'Bearer',
                'expires_in'    => self::ACCESS_TOKEN_TTL,
                'refresh_token' => $newrt,
                'scope'         => $rec->scope,
            ]);
            return;
        }

        http::oauth_error(400, 'unsupported_grant_type');
    }

    /**
     * Persist a fresh access/refresh token pair.
     *
     * @param string|null $clientid OAuth client identifier.
     * @param int         $userid   Moodle user id.
     * @param string      $scope    Granted scopes.
     * @param string      $source   Audit source (oauth|refresh|generate).
     * @return array{0:string,1:string} [access_token, refresh_token].
     */
    private function issue_tokens(?string $clientid, int $userid, string $scope, string $source = 'oauth'): array {
        global $DB;

        $accesstoken  = 'xfa_' . $this->make_token();
        $refreshtoken = 'xfr_' . $this->make_token();
        $now = time();
        $expiresat = $now + self::ACCESS_TOKEN_TTL;

        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token'       => $accesstoken,
            'tokentype'   => 'access',
            'client_id'   => $clientid,
            'userid'      => $userid,
            'scope'       => $scope,
            'expiry'      => $expiresat,
            'revoked'     => 0,
            'timecreated' => $now,
        ]);

        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token'       => $refreshtoken,
            'tokentype'   => 'refresh',
            'client_id'   => $clientid,
            'userid'      => $userid,
            'scope'       => $scope,
            'parenttoken' => $accesstoken,
            'expiry'      => $now + self::REFRESH_TOKEN_TTL,
            'revoked'     => 0,
            'timecreated' => $now,
        ]);

        token_registry::register($accesstoken, $userid, (string) $clientid, $scope, $expiresat, $source);

        return [$accesstoken, $refreshtoken];
    }

    /**
     * RFC 7009 token revocation endpoint.
     *
     * Public by design: no Bearer, cookie, sesskey or require_login — whoever
     * holds a token (a public PKCE client such as goldnat) may kill it.
     * `token` may be the access OR the refresh half of a pair; either one
     * revokes the whole pair. `token_type_hint` (access_token|refresh_token)
     * only changes the lookup order. Unknown / already revoked tokens still
     * get a 200 so callers cannot probe for valid tokens; only a missing
     * `token` yields 400 invalid_request. Never redirects.
     */
    public function revoke(): void {
        global $DB;

        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        // Accept application/x-www-form-urlencoded (RFC 7009) and JSON.
        $contenttype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($contenttype, 'application/json') !== false) {
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($body)) {
                foreach ($body as $k => $v) {
                    if (!isset($_POST[$k])) {
                        $_POST[$k] = is_scalar($v) ? (string) $v : '';
                    }
                }
            }
        }

        $token = optional_param('token', '', PARAM_RAW_TRIMMED);
        $hint  = optional_param('token_type_hint', '', PARAM_ALPHANUMEXT);

        // Legacy fallback (pre-2.2.1 clients): token sent as a Bearer header.
        if ($token === '') {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (str_starts_with($header, 'Bearer ')) {
                $token = trim(substr($header, 7));
            }
        }
        if ($token === '') {
            http::oauth_error(400, 'invalid_request', 'token is required');
        }

        $types = $hint === 'refresh_token' ? ['refresh', 'access'] : ['access', 'refresh'];

        // Hash-first, plaintext fallback: rows are stored plaintext today, the
        // SHA-256 probe keeps this correct once tokens are hashed at rest.
        $row = null;
        foreach ($types as $type) {
            foreach ([hash('sha256', $token), $token] as $candidate) {
                $row = $DB->get_record('local_aiconnect_tokens', [
                    'token' => $candidate, 'tokentype' => $type,
                ], '*', IGNORE_MULTIPLE);
                if ($row) {
                    break 2;
                }
            }
        }

        if ($row) {
            // Resolve the access half of the pair; refresh rows point to it.
            $accesstoken = $row->tokentype === 'access' ? (string) $row->token : (string) $row->parenttoken;

            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->set_field('local_aiconnect_tokens', 'revoked', 1, ['id' => $row->id]);
                if ($accesstoken !== '') {
                    $DB->set_field('local_aiconnect_tokens', 'revoked', 1, [
                        'token' => $accesstoken, 'tokentype' => 'access',
                    ]);
                    $DB->set_field('local_aiconnect_tokens', 'revoked', 1, [
                        'parenttoken' => $accesstoken, 'tokentype' => 'refresh',
                    ]);
                    // Sync the registry (admin token list + "My tokens") — it is
                    // also the plugin's audit trail (revoked_at / revoked_by).
                    // revoked_by = 0: revoked by the token holder via RFC 7009.
                    token_registry::revoke($accesstoken, 0);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            }
        }

        // Same answer whether or not the token was known (no probing).
        http_response_code(200);
        echo json_encode(['success' => true]);
    }

    /**
     * Helper endpoint — issues a Bearer token for the logged-in user.
     *
     * Used by the info.php "Generate Token" button so admins can call the
     * API from curl without running the full OAuth dance.
     */
    public function generate_token(): void {
        global $USER;

        require_login(null, false);
        require_sesskey();

        $context = \context_system::instance();
        require_capability('local/aiconnect:use', $context);

        [$accesstoken, $refreshtoken] = $this->issue_tokens('claude', (int) $USER->id, 'read write', 'generate');

        echo json_encode([
            'access_token'  => $accesstoken,
            'refresh_token' => $refreshtoken,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TOKEN_TTL,
            'prompt'        => $this->build_prompt($accesstoken, $refreshtoken),
        ]);
    }

    /**
     * Build a copy-paste prompt for AI agents (Servio Protocol format).
     *
     * @param string $accesstoken  The generated access token.
     * @param string $refreshtoken The generated refresh token.
     * @return string Complete prompt text.
     */
    private function build_prompt(string $accesstoken, string $refreshtoken): string {
        global $CFG, $SITE;

        $base     = rtrim($CFG->wwwroot, '/');
        $parsed   = parse_url($base);
        $host     = $parsed['host'] ?? 'moodle';
        $path     = rtrim($parsed['path'] ?? '', '/');
        $sitemcp  = $path !== '' ? $host . $path : $host;
        $sitekey  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sitemcp);
        $sitename = !empty($SITE->fullname) ? $SITE->fullname : $sitemcp;

        $manifest = $base . '/api/aiconnect-manifest';
        $tokenurl = $base . '/api/aiconnect-oauth';

        $readtools = [
            ['name' => 'moodle.getCourses',     'hint' => 'List available courses'],
            ['name' => 'moodle.getCourse',       'hint' => 'Get course details by ID'],
            ['name' => 'moodle.getEnrollments',  'hint' => 'List user enrollments'],
            ['name' => 'moodle.getAssignments',  'hint' => 'List course assignments'],
            ['name' => 'moodle.getGrades',       'hint' => 'Get grade data for a course'],
            ['name' => 'moodle.getUserProfile',  'hint' => 'Get user profile information'],
        ];

        $lines   = [];
        $lines[] = 'You have access to ' . $sitename . ' via AI Connect.';
        $lines[] = '';
        $lines[] = '## Add site (goldnat.ai recommended)';
        $lines[] = 'Call servio_addSite (or webmcp_addSite for backward compat) with these parameters:';
        $lines[] = '  name:          "' . $sitemcp . '"';
        $lines[] = '  manifest_url:  "' . $manifest . '"';
        $lines[] = '  token_url:     "' . $tokenurl . '"';
        $lines[] = '  token:         "Bearer ' . $accesstoken . '"';
        $lines[] = '  refresh_token: "' . $refreshtoken . '"';
        $lines[] = '';
        $lines[] = 'IMPORTANT: Paste BOTH token and refresh_token — otherwise the connection will stop working after 1 hour.';
        $lines[] = '';
        $lines[] = 'After adding the site, call these tools by EXACT name (do not search — use directly):';
        foreach ($readtools as $t) {
            $mcpname = $sitekey . '_' . str_replace('.', '_', $t['name']);
            $lines[] = '  ' . str_pad($mcpname, 50) . '<- ' . $t['hint'];
        }
        $lines[] = '';
        $lines[] = '## Token Refresh (valid 30 days)';
        $lines[] = 'When access_token expires (after 1 hour), refresh it:';
        $lines[] = '  POST ' . $tokenurl;
        $lines[] = '  Content-Type: application/json';
        $lines[] = '  {"grant_type":"refresh_token","refresh_token":"' . $refreshtoken . '","client_id":"claude"}';
        $lines[] = 'Response contains a new access_token + new refresh_token (old pair is revoked).';
        $lines[] = '';
        $lines[] = '## Revoke a token (when you no longer need access)';
        $lines[] = 'To immediately revoke a token — for example when the task is done or a token may be exposed:';
        $lines[] = '  POST ' . $base . '/api/aiconnect-oauth?action=revoke';
        $lines[] = '  Content-Type: application/json';
        $lines[] = '  {"token":"' . $accesstoken . '"}';
        $lines[] = 'You (the user) can also review and revoke tokens anytime — including bulk-cleanup of unused/inactive';
        $lines[] = 'tokens — on the My Tokens page: ' . $base . '/local/aiconnect/mytokens.php';
        $lines[] = '';
        $lines[] = 'IMPORTANT: Do NOT use webmcp tool search — it may return tools from other sites.';
        $lines[] = 'Call the tools listed above by their EXACT full name.';
        $lines[] = 'Security note: This token acts on behalf of the user who generated it. Handle it with care.';
        $lines[] = 'Documentation: https://plugins.goldnat.ai/moodle/guide';

        return implode("\n", $lines);
    }
}
