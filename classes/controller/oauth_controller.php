<?php
namespace local_aiconnect\controller;

class oauth_controller {

    private function get_db(): \moodle_database {
        global $DB;
        return $DB;
    }

    private function verify_pkce(string $verifier, string $challenge): bool {
        return base64_encode(hash('sha256', $verifier, true)) === strtr($challenge . '==', '-_', '+/');
    }

    private function make_token(): string {
        return bin2hex(random_bytes(32));
    }

    public function authorize(): void {
        global $USER, $SESSION, $CFG;
        require_login();

        $client_id    = required_param('client_id', PARAM_ALPHANUMEXT);
        $challenge    = required_param('code_challenge', PARAM_RAW);
        $method       = optional_param('code_challenge_method', 'S256', PARAM_ALPHA);
        $redirect_uri = optional_param('redirect_uri', '', PARAM_URL);
        $scope        = optional_param('scope', 'read write', PARAM_RAW);
        $state        = optional_param('state', '', PARAM_RAW);

        $DB = $this->get_db();
        if (!$DB->record_exists('local_aiconnect_clients', ['client_id' => $client_id])) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_client']);
            exit;
        }

        $context = \context_system::instance();
        if (!has_capability('local/aiconnect:use', $context)) {
            http_response_code(403);
            echo json_encode(['error' => 'insufficient_permissions']);
            exit;
        }

        if (optional_param('approved', '', PARAM_ALPHA) === 'true') {
            $code = $this->make_token();
            $DB->insert_record('local_aiconnect_codes', [
                'code' => $code, 'client_id' => $client_id, 'userid' => $USER->id,
                'scope' => $scope, 'code_challenge' => $challenge,
                'redirect_uri' => $redirect_uri, 'expiry' => time() + 600, 'used' => 0,
            ]);
            $dest = ($redirect_uri && $redirect_uri !== 'urn:ietf:wg:oauth:2.0:oob')
                ? "$redirect_uri?code=$code&state=$state"
                : new \moodle_url('/local/aiconnect/oauth.php', ['action' => 'callback', 'code' => $code, 'state' => $state]);
            redirect($dest);
            return;
        }

        $client = $DB->get_record('local_aiconnect_clients', ['client_id' => $client_id]);
        $page_url = new \moodle_url('/local/aiconnect/oauth.php', ['action' => 'authorize']);
        echo $this->render_consent($client->client_name, $scope, $client_id, $challenge, $redirect_uri, $state);
    }

    private function render_consent(string $name, string $scope, string $cid, string $ch, string $ru, string $st): string {
        global $SESSION;
        $sesskey = sesskey();
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><title>AI Connect Authorization</title>
<style>body{font-family:sans-serif;max-width:500px;margin:80px auto;padding:20px}
.btn{padding:10px 20px;border:none;border-radius:4px;cursor:pointer;font-size:15px}
.approve{background:#4CAF50;color:#fff}.deny{background:#f44336;color:#fff;margin-left:8px}</style></head><body>
<h2>AI Connect Authorization</h2>
<p>The agent <strong>$name</strong> is requesting access.</p>
<p>Scopes: <strong>$scope</strong></p>
<form method="POST" action="/local/aiconnect/oauth.php?action=authorize">
<input type="hidden" name="sesskey" value="$sesskey">
<input type="hidden" name="client_id" value="$cid">
<input type="hidden" name="code_challenge" value="$ch">
<input type="hidden" name="redirect_uri" value="$ru">
<input type="hidden" name="scope" value="$scope">
<input type="hidden" name="state" value="$st">
<input type="hidden" name="approved" value="true">
<button type="submit" class="btn approve">Approve</button>
</form>
<form method="POST" action="/local/aiconnect/oauth.php?action=deny" style="display:inline">
<input type="hidden" name="sesskey" value="$sesskey">
<button type="submit" class="btn deny">Deny</button>
</form></body></html>
HTML;
    }

    public function token(): void {
        $DB = $this->get_db();
        $grant = required_param('grant_type', PARAM_ALPHANUMEXT);

        if ($grant === 'authorization_code') {
            $code     = required_param('code', PARAM_RAW);
            $verifier = required_param('code_verifier', PARAM_RAW);
            $rec = $DB->get_record('local_aiconnect_codes', ['code' => $code]);
            if (!$rec || $rec->used || time() > $rec->expiry) {
                http_response_code(400); echo json_encode(['error' => 'invalid_grant']); exit;
            }
            if (!$this->verify_pkce($verifier, $rec->code_challenge)) {
                http_response_code(400); echo json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE failed']); exit;
            }
            $DB->set_field('local_aiconnect_codes', 'used', 1, ['id' => $rec->id]);
            [$at, $rt] = $this->issue_tokens($rec->client_id, $rec->userid, $rec->scope);
            echo json_encode(['access_token' => $at, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $rt, 'refresh_token_expires_in' => 2592000, 'scope' => $rec->scope]);
            return;
        }

        if ($grant === 'refresh_token') {
            $rt = required_param('refresh_token', PARAM_RAW);
            $rec = $DB->get_record('local_aiconnect_tokens', ['token' => $rt, 'tokentype' => 'refresh', 'revoked' => 0]);
            if (!$rec || time() > $rec->expiry) {
                http_response_code(400); echo json_encode(['error' => 'invalid_grant']); exit;
            }
            $DB->set_field('local_aiconnect_tokens', 'revoked', 1, ['id' => $rec->id]);
            [$at, $newrt] = $this->issue_tokens($rec->client_id, $rec->userid, $rec->scope);
            echo json_encode(['access_token' => $at, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $newrt, 'scope' => $rec->scope]);
            return;
        }

        http_response_code(400); echo json_encode(['error' => 'unsupported_grant_type']);
    }

    private function issue_tokens(string $client_id, int $userid, string $scope): array {
        $DB = $this->get_db();
        $at = 'mdl_' . $this->make_token();
        $rt = 'mdlr_' . $this->make_token();
        $now = time();
        $DB->insert_record('local_aiconnect_tokens', ['token' => $at, 'tokentype' => 'access', 'client_id' => $client_id, 'userid' => $userid, 'scope' => $scope, 'expiry' => $now + 3600, 'revoked' => 0, 'timecreated' => $now]);
        $DB->insert_record('local_aiconnect_tokens', ['token' => $rt, 'tokentype' => 'refresh', 'client_id' => $client_id, 'userid' => $userid, 'scope' => $scope, 'parenttoken' => $at, 'expiry' => $now + 2592000, 'revoked' => 0, 'timecreated' => $now]);
        return [$at, $rt];
    }

    public function revoke(): void {
        $DB = $this->get_db();
        $headers = getallheaders();
        $token = ltrim($headers['Authorization'] ?? '', 'Bearer ');
        if ($token) $DB->set_field('local_aiconnect_tokens', 'revoked', 1, ['token' => $token]);
        echo json_encode(['success' => true]);
    }

    public function generate_token(): void {
        global $USER;
        require_login();
        $context = \context_system::instance();
        require_capability('local/aiconnect:use', $context);
        [,$at] = [null, null];
        [$at, ] = $this->issue_tokens('ai-agent-default', $USER->id, 'read write');
        echo json_encode(['access_token' => $at, 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }
}
