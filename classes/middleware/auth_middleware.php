<?php
namespace local_aiconnect\middleware;

class auth_middleware {

    public static function require_bearer(): array {
        global $DB;
        $headers = getallheaders();
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer realm="AI Connect"');
            echo json_encode(['error' => 'unauthorized', 'error_description' => 'Bearer token required']);
            exit;
        }
        $token = substr($header, 7);
        $rec   = $DB->get_record('local_aiconnect_tokens', ['token' => $token, 'tokentype' => 'access', 'revoked' => 0]);
        if (!$rec || time() > $rec->expiry) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_token', 'error_description' => 'Token expired or revoked']);
            exit;
        }
        return ['userid' => (int)$rec->userid, 'scope' => $rec->scope, 'token' => $token];
    }

    public static function check_rate_limit(string $token): void {
        global $DB;
        $per_minute = (int)(get_config('local_aiconnect', 'ratelimit_minute') ?: 50);
        $per_hour   = (int)(get_config('local_aiconnect', 'ratelimit_hour') ?: 1000);
        $now = time();

        foreach ([['minute', $now - ($now % 60), $per_minute], ['hour', $now - ($now % 3600), $per_hour]] as [$wtype, $wstart, $limit]) {
            $rec = $DB->get_record('local_aiconnect_ratelimits', ['token' => $token, 'windowtype' => $wtype]);
            if ($rec && $rec->windowstart == $wstart) {
                if ($rec->requestcount >= $limit) {
                    http_response_code(429);
                    echo json_encode(['error' => 'rate_limit_exceeded', 'window' => $wtype]);
                    exit;
                }
                $DB->set_field('local_aiconnect_ratelimits', 'requestcount', $rec->requestcount + 1, ['id' => $rec->id]);
            } else {
                $DB->delete_records('local_aiconnect_ratelimits', ['token' => $token, 'windowtype' => $wtype]);
                $DB->insert_record('local_aiconnect_ratelimits', ['token' => $token, 'windowtype' => $wtype, 'windowstart' => $wstart, 'requestcount' => 1]);
            }
        }
    }
}
