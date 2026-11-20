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
 * User confirmation flow (user-confirm-v1) for destructive AI Connect tools.
 *
 * A tool that declares `confirmation` is NOT executed when an agent calls it.
 * The gate stores a pending action bound to user + tool + SHA-256 of the
 * canonical arguments + client and answers HTTP 202 `confirmation_required`
 * with a summary in the user's language and a confirm_url on this site. Only
 * the Moodle user who owns the token can confirm or reject it, on
 * /local/aiconnect/aiactions.php (logged-in session + sesskey; requests that
 * carry an Authorization header are refused). Confirming runs the tool once,
 * as that user. Re-submitting with confirmation_id never executes — it only
 * reports. Contract: CONFIRMATION-FLOW-SPEC v1.1 / Servio §6.5.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\local;

use local_aiconnect\module\module_base;
use local_aiconnect\module\module_registry;
use local_aiconnect\service\user_blocking;

/**
 * Pending-action store, gate and user decisions.
 */
class confirmation {
    /** @var string Flow identifier published in the manifest and every envelope. */
    public const FLOW = 'user-confirm-v1';

    /** @var int Default lifetime of a pending action, in seconds. */
    public const DEFAULT_TTL = 900;

    /** @var int Days decided actions and log rows are kept. */
    public const KEEP_DAYS = 30;

    /** @var int Seconds after execution in which a repeated identical call gets the stored result. */
    public const RECENT_WINDOW = 120;

    /** @var array<int,string> Request keys that steer the flow and never take part in the hash. */
    public const CONTROL_KEYS = ['confirmation_id', 'confirmation_new', 'confirm'];

    /** @var string Pending actions table. */
    public const TABLE = 'local_aiconnect_pending';

    /** @var string Autonomy grants table. */
    public const GRANTS = 'local_aiconnect_autonomy';

    /** @var string Append-only audit log table. */
    public const LOG = 'local_aiconnect_conflog';

    /** @var array|null The one tool + argument hash a confirmed execution may run. */
    private static ?array $expected = null;

    // Declaration.

    /**
     * Normalise a tool's `confirmation` declaration.
     *
     * @param mixed  $decl  'required', true, or ['required' => bool|callable, 'risk', 'summary', 'preview'].
     * @param string $scope The tool's required scope; read tools are never gated.
     * @return array|null Normalised declaration or null when the tool is not gated.
     */
    public static function normalise($decl, string $scope): ?array {
        if ($decl === null || $decl === false || $scope === 'read') {
            return null;
        }
        if ($decl === true || $decl === 'required') {
            $decl = ['required' => true];
        }
        if (!is_array($decl)) {
            return null;
        }
        $required = $decl['required'] ?? true;
        if (!is_callable($required)) {
            $required = (bool) $required;
            if (!$required) {
                return null;
            }
        }
        $risk = (string) ($decl['risk'] ?? 'destructive');
        if (!in_array($risk, ['destructive', 'financial'], true)) {
            $risk = 'destructive';
        }
        return [
            'required' => $required,
            'risk'     => $risk,
            'summary'  => (string) ($decl['summary'] ?? ''),
            'preview'  => $decl['preview'] ?? null,
        ];
    }

    /**
     * Manifest value for a declaration.
     *
     * @param array $conf Normalised declaration.
     * @return string 'required' or 'conditional'.
     */
    public static function mode(array $conf): string {
        return $conf['required'] === true ? 'required' : 'conditional';
    }

    /**
     * Remove the agent-side `confirm` flag from a tool schema (it means nothing any more).
     *
     * @param array $schema JSON schema.
     * @return array
     */
    public static function strip_confirm_from_schema(array $schema): array {
        if (isset($schema['properties'])) {
            $props = (array) $schema['properties'];
            unset($props['confirm']);
            $schema['properties'] = (object) $props;
        }
        if (isset($schema['required']) && is_array($schema['required'])) {
            $schema['required'] = array_values(array_diff($schema['required'], ['confirm']));
            if (!$schema['required']) {
                unset($schema['required']);
            }
        }
        return $schema;
    }

    /**
     * Sentence appended to a gated tool's description.
     *
     * @param array $conf Normalised declaration.
     * @return string
     */
    public static function description_suffix(array $conf): string {
        $when = $conf['required'] === true ? 'always needs' : 'may need';
        return ' This action ' . $when . ' the confirmation of the signed-in user: the call answers HTTP 202'
            . ' confirmation_required with a summary and confirm_url, and nothing happens until the user'
            . ' confirms on the site.';
    }

    /**
     * Arguments without flow control keys.
     *
     * @param array $params Raw arguments.
     * @return array
     */
    public static function strip_args(array $params): array {
        foreach (self::CONTROL_KEYS as $key) {
            unset($params[$key]);
        }
        return $params;
    }

    /**
     * SHA-256 of the canonical JSON of the arguments (keys sorted at every level).
     *
     * @param array $args Arguments without control keys.
     * @return string 64 hex chars.
     */
    public static function args_hash(array $args): string {
        return hash('sha256', (string) json_encode(self::ksort_deep($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Recursively sort associative keys (lists keep their order).
     *
     * @param mixed $value Value.
     * @return mixed
     */
    private static function ksort_deep($value) {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $islist = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$islist) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::ksort_deep($v);
        }
        return $value;
    }

    // Gate.

    /**
     * Gate a call to a tool that declares confirmation.
     *
     * Called by module_base::execute_tool after the scope and capability checks.
     *
     * @param module_base $module  Owning module.
     * @param string      $short   Tool short name.
     * @param array       $tool    Tool definition (name, confirmation, required_scope).
     * @param array       $params  Raw arguments (may carry confirmation_id / confirmation_new).
     * @param array       $context Request context: userid, scope, clientid, tokenprefix.
     * @return array|null Null = run the tool now; otherwise the answer to send back.
     */
    public static function gate(module_base $module, string $short, array $tool, array $params, array $context): ?array {
        $conf = $tool['confirmation'];
        $full = (string) $tool['name'];
        $args = self::strip_args($params);
        $hash = self::args_hash($args);

        // Confirmed execution started from the user's page: only that tool + those arguments.
        if (self::$expected !== null) {
            if (self::$expected['tool'] === $full && hash_equals(self::$expected['hash'], $hash)) {
                return null;
            }
            return self::error('confirmation_mismatch', 409, 'Only the confirmed action may run.');
        }

        if (is_callable($conf['required']) && !call_user_func($conf['required'], $args, $context)) {
            return null;
        }

        $userid = (int) ($context['userid'] ?? 0);
        if ($userid <= 0 || isguestuser($userid)) {
            return self::error('confirmation_unavailable', 403, 'This action needs a signed-in user who can confirm it.');
        }

        self::sweep();
        $clientid  = (string) ($context['clientid'] ?? '');
        $clientkey = self::client_key($clientid);

        $confid = isset($params['confirmation_id']) ? trim((string) $params['confirmation_id']) : '';
        if ($confid !== '') {
            return self::resubmission($confid, $full, $hash, $userid, $clientid);
        }

        if (self::autonomy_allowed() && ($grant = self::find_grant($userid, $full, $clientid))) {
            self::log('autonomous_execute', '', $userid, $full, $clientid, ['grant_id' => (int) $grant->id]);
            return null;
        }

        $again = isset($params['confirmation_new']) && in_array($params['confirmation_new'], [true, 1, '1', 'true'], true);
        if (!$again) {
            $done = self::find_recent_executed($userid, $full, $hash, $clientkey);
            if ($done) {
                self::log('duplicate_blocked', $done->confid, $userid, $full, $clientid);
                return self::executed_answer($done, true);
            }
        }

        $existing = self::find_pending($userid, $full, $hash, $clientkey);
        if ($existing) {
            self::log('reused', $existing->confid, $userid, $full, $clientid);
            return self::pending_envelope($existing, true);
        }

        // Preview + user's summary, in the user's language. No side effect.
        $lang = self::user_lang($userid);
        $previous = force_current_language($lang);
        try {
            $preview = self::build_preview($module, $conf, $short, $args, $context);
            if (isset($preview['error'])) {
                return $preview;
            }
            $summary = self::resolve_summary($conf, $full, $args, $preview);
        } finally {
            force_current_language($previous);
        }
        $details = [];
        if (!empty($preview['details']) && is_array($preview['details'])) {
            foreach (array_slice($preview['details'], 0, 10) as $line) {
                $line = self::clean((string) $line);
                if ($line !== '') {
                    $details[] = $line;
                }
            }
        }
        unset($preview['summary'], $preview['details']);

        $action = self::create_pending($userid, $full, $args, $hash, $conf, $preview, $summary, $details, $lang, $context);
        self::log('requested', $action->confid, $userid, $full, $clientid);
        \local_aiconnect\event\ai_action_requested::create_from_action($action)->trigger();
        return self::pending_envelope($action, false);
    }

    /**
     * Build the preview (validates and checks permissions; must not change anything).
     *
     * @param module_base $module  Module.
     * @param array       $conf    Declaration.
     * @param string      $short   Tool short name.
     * @param array       $args    Arguments.
     * @param array       $context Request context.
     * @return array Preview, or an error array (nothing is queued).
     */
    private static function build_preview(module_base $module, array $conf, string $short, array $args, array $context): array {
        $p = $conf['preview'];
        try {
            if (is_string($p) && $p !== '' && method_exists($module, $p)) {
                $out = $module->{$p}($args, $context);
            } else if (is_callable($p)) {
                $out = call_user_func($p, $args, $context);
            } else {
                return ['arguments' => $args];
            }
        } catch (\invalid_parameter_exception $e) {
            return ['error' => 'tool_error', 'message' => $e->getMessage()];
        } catch (\required_capability_exception $e) {
            return ['error' => 'insufficient_capability', 'statusCode' => 403, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            debugging('local_aiconnect confirmation preview ' . $short . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['error' => 'preview_failed', 'message' => 'Could not prepare the preview: ' . $e->getMessage()];
        }
        return is_array($out) ? $out : ['preview' => $out];
    }

    /**
     * The one sentence the user reads (never empty, no markup, ≤ 255 chars).
     *
     * @param array  $conf    Declaration.
     * @param string $full    Tool name.
     * @param array  $args    Arguments.
     * @param array  $preview Preview (may carry summary / what).
     * @return string
     */
    public static function resolve_summary(array $conf, string $full, array $args, array $preview): string {
        $summary = isset($preview['summary']) && is_scalar($preview['summary']) ? (string) $preview['summary'] : '';
        if (self::clean($summary) === '' && $conf['summary'] !== '') {
            $vals = [];
            foreach ($preview as $k => $v) {
                if (is_scalar($v)) {
                    $vals[(string) $k] = (string) $v;
                }
            }
            foreach ($args as $k => $v) {
                if (is_scalar($v)) {
                    $vals[(string) $k] = (string) $v;
                }
            }
            $summary = preg_replace_callback('/\{(\w+)\}/', static function ($m) use ($vals) {
                return $vals[$m[1]] ?? '';
            }, $conf['summary']);
        }
        if (self::clean((string) $summary) === '' && isset($preview['what']) && is_scalar($preview['what'])) {
            $summary = (string) $preview['what'];
        }
        $summary = self::clean((string) $summary);
        if ($summary === '') {
            $summary = self::clean(get_string('confirm_generic', 'local_aiconnect', $full));
        }
        return \core_text::substr($summary, 0, 255);
    }

    /**
     * Strip markup and collapse whitespace.
     *
     * @param string $s Text.
     * @return string
     */
    private static function clean(string $s): string {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Answer to a re-submission carrying confirmation_id: report, never execute.
     *
     * @param string $confid   Confirmation id.
     * @param string $full     Tool.
     * @param string $hash     Arguments hash.
     * @param int    $userid   User.
     * @param string $clientid Client.
     * @return array
     */
    private static function resubmission(string $confid, string $full, string $hash, int $userid, string $clientid): array {
        $action = self::get_action($confid);
        if (!$action || (int) $action->userid !== $userid) {
            return self::error('confirmation_not_found', 404, 'Unknown confirmation_id for this user.');
        }
        if ($action->tool !== $full || !hash_equals((string) $action->argshash, $hash)) {
            self::log('mismatch', $confid, $userid, $full, $clientid);
            return self::error(
                'confirmation_mismatch',
                409,
                'confirmation_id belongs to a different tool or different arguments.'
                    . ' A confirmation covers exactly the previewed call.'
            );
        }
        switch ($action->status) {
            case 'pending':
                return self::pending_envelope($action, true);
            case 'executing':
                return self::error(
                    'confirmation_in_progress',
                    409,
                    'The user confirmed; the action is running. Check status_url shortly.'
                );
            case 'executed':
            case 'failed':
                self::log('replay_blocked', $confid, $userid, $full, $clientid);
                return self::executed_answer($action, false);
            case 'rejected':
                return self::error(
                    'confirmation_rejected',
                    410,
                    'The user rejected this action. Do not retry it unless the user asks again.'
                );
            default:
                return self::error(
                    'confirmation_expired',
                    410,
                    'This confirmation expired before the user confirmed it. Ask the user whether to start again.'
                );
        }
    }

    /**
     * Stored result of an action that ran (it never runs again).
     *
     * @param \stdClass $action    Action row.
     * @param bool      $duplicate True when the agent repeated the call without confirmation_id.
     * @return array
     */
    private static function executed_answer(\stdClass $action, bool $duplicate): array {
        $result = json_decode((string) $action->resultjson, true);
        $when = self::iso((int) $action->timeexecuted);
        $note = 'Already executed exactly once, at ' . $when . ', after the user confirmed. This call did NOT run it again.';
        if ($duplicate) {
            $note .= ' Do not call this tool again for the same request. Only if the user explicitly asks to do the very'
                . ' same thing a second time, repeat the call with "confirmation_new": true.';
        }
        $meta = [
            'confirmation_id'  => $action->confid,
            'status'           => $action->status,
            'already_executed' => true,
            'executed_at'      => $when,
            'summary'          => (string) $action->summary,
            'note'             => $note,
        ];
        self::mark_delivered($action);
        if ($action->status === 'failed') {
            $code = is_array($result) && !empty($result['error']) ? (string) $result['error'] : 'execution_failed';
            $msg = is_array($result) && isset($result['message']) ? (string) $result['message'] : 'The confirmed action failed.';
            return [
                'error'        => $code,
                'message'      => 'The user confirmed this action and it ran once, at ' . $when . ', but it FAILED: ' . $msg
                    . ' It was not run again by this call.',
                'confirmation' => $meta,
            ];
        }
        if (!is_array($result)) {
            $result = ['result' => $result];
        }
        $result['confirmation'] = $meta;
        $result['message'] = 'Already executed - the user confirmed and it ran once at ' . $when
            . '. Not run again by this call; this is the result of that run.';
        return $result;
    }

    /**
     * The 202 answer for a pending action.
     *
     * `summary` and `user_message` are for the user (their language); `message` is for the agent.
     *
     * @param \stdClass $action Action row.
     * @param bool      $reused True when the same request was already waiting.
     * @return array ['error' => 'confirmation_required', 'statusCode' => 202, 'envelope' => [...]]
     */
    public static function pending_envelope(\stdClass $action, bool $reused): array {
        $preview = json_decode((string) $action->previewjson, true);
        $preview = is_array($preview) ? $preview : [];
        $details = json_decode((string) $action->details, true);
        $preview['details'] = is_array($details) ? $details : [];
        $summary = (string) $action->summary;
        $usermessage = get_string_manager()->get_string(
            'confirm_user_message',
            'local_aiconnect',
            rtrim($summary, '.'),
            $action->lang ?: null
        );

        $env = [
            'confirmation_id' => $action->confid,
            'confirm_url'     => self::confirm_url($action->confid),
            'status_url'      => self::status_url($action->confid),
            'expires_at'      => self::iso((int) $action->expiresat),
            'expires_in'      => max(0, (int) $action->expiresat - time()),
            'tool'            => $action->tool,
            'risk'            => $action->risk,
            'summary'         => $summary,
            'user_message'    => $usermessage,
            'preview'         => $preview,
            'flow'            => self::FLOW,
        ];
        if ($reused) {
            $env['reused'] = true;
        }
        $message = ($reused
                ? 'Not executed, and NO new request was created: this exact action is already waiting for the user'
                    . ' (same confirmation_id). '
                : 'Not executed. ')
            . 'This action needs the user\'s own confirmation. Tell the user in plain words what is waiting - use the "summary"'
            . ' field - and ask them to open confirm_url (signed in to Moodle) to approve or decline. Do NOT call this tool'
            . ' again on your own and do not tell the user it was done. When the user says they confirmed, call it again with'
            . ' the same arguments plus confirmation_id (or GET status_url) to get the result.'
            . ' (This "message" is an instruction for you, the agent - never show it to the user.)';
        $body = array_merge(
            ['success' => false, 'status' => 'pending_confirmation', 'code' => 'confirmation_required', 'message' => $message],
            $env,
            ['data' => $env]
        );
        return ['error' => 'confirmation_required', 'statusCode' => 202, 'message' => $message, 'envelope' => $body];
    }

    /**
     * Error array understood by tools.php.
     *
     * @param string $code   Error code.
     * @param int    $status HTTP status.
     * @param string $msg    Message.
     * @return array
     */
    private static function error(string $code, int $status, string $msg): array {
        return ['error' => $code, 'statusCode' => $status, 'message' => $msg];
    }

    // User step.

    /**
     * The user confirms on the AI actions page: run the action once, as that user.
     *
     * The caller (aiactions.php) has checked require_login + sesskey + no Authorization header.
     *
     * @param string               $confid        Confirmation id.
     * @param int                  $userid        Logged-in user (must own the action).
     * @param bool                 $grantautonomy Also let this client run this tool without asking.
     * @param module_registry|null $registry      Test seam.
     * @return array{ok:bool,status:string,result?:mixed,error?:string}
     */
    public static function confirm(
        string $confid,
        int $userid,
        bool $grantautonomy = false,
        ?module_registry $registry = null
    ): array {
        global $DB;
        $action = self::get_action($confid);
        if (!$action || (int) $action->userid !== $userid) {
            return ['ok' => false, 'status' => 'not_found', 'error' => 'Unknown action.'];
        }
        // Single use: only one request can move pending → executing.
        $claim = bin2hex(random_bytes(16));
        $now = time();
        $DB->execute(
            'UPDATE {' . self::TABLE . "} SET status = 'executing', claim = ?, timedecided = ?, decidedby = ?
              WHERE confid = ? AND userid = ? AND status = 'pending' AND expiresat > ?",
            [$claim, $now, $userid, $confid, $userid, $now]
        );
        $action = self::get_action($confid);
        if (!$action || $action->status !== 'executing' || (string) $action->claim !== $claim) {
            self::log(
                'replay_blocked',
                $confid,
                $userid,
                $action->tool ?? '',
                $action->clientid ?? '',
                ['status' => $action->status ?? '']
            );
            return ['ok' => false, 'status' => (string) ($action->status ?? 'unknown'),
                'error' => 'This action can no longer be confirmed (already decided or expired).'];
        }
        self::log('confirmed', $confid, $userid, $action->tool, $action->clientid);

        $args = json_decode((string) $action->argsjson, true);
        $args = is_array($args) ? $args : [];
        $result = self::execute_as_confirmed($action, $args, $registry);
        $failed = isset($result['error']);

        $DB->update_record(self::TABLE, (object) [
            'id'           => $action->id,
            'status'       => $failed ? 'failed' : 'executed',
            'resultjson'   => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeexecuted' => time(),
        ]);
        self::log($failed ? 'execution_failed' : 'executed', $confid, $userid, $action->tool, $action->clientid);
        $action = self::get_action($confid);
        \local_aiconnect\event\ai_action_confirmed::create_from_action($action)->trigger();

        if ($grantautonomy && self::autonomy_allowed()) {
            self::grant($userid, $action->tool, (string) $action->clientid);
        }
        return ['ok' => !$failed, 'status' => $failed ? 'failed' : 'executed', 'result' => $result];
    }

    /**
     * Run the tool in confirmed mode through the registry (scope, capability and the
     * tool's own Moodle permission checks run again, as the user).
     *
     * @param \stdClass            $action   Action row.
     * @param array                $args     Stored arguments.
     * @param module_registry|null $registry Test seam.
     * @return array
     */
    private static function execute_as_confirmed(\stdClass $action, array $args, ?module_registry $registry): array {
        global $DB;
        $userid = (int) $action->userid;
        if (user_blocking::is_user_blocked($userid)) {
            return self::error('user_blocked', 403, 'Your access to AI Connect has been blocked.');
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user || !empty($user->suspended)) {
            return self::error('user_not_permitted', 403, 'The account is no longer active.');
        }
        if ($action->tokenprefix !== '' && self::token_revoked((string) $action->tokenprefix)) {
            return self::error('token_revoked', 403, 'The connection that asked for this action was revoked.');
        }
        $registry = $registry ?? new module_registry();
        self::$expected = ['tool' => (string) $action->tool, 'hash' => self::args_hash($args)];
        try {
            return $registry->execute((string) $action->tool, $args, [
                'userid'      => $userid,
                'scope'       => (string) $action->scope,
                'clientid'    => (string) $action->clientid,
                'tokenprefix' => (string) $action->tokenprefix,
                'confirmed'   => true,
            ]);
        } finally {
            self::$expected = null;
        }
    }

    /**
     * Is the token that asked for the action revoked?
     *
     * @param string $prefix Token prefix (16 chars).
     * @return bool
     */
    private static function token_revoked(string $prefix): bool {
        global $DB;
        $rows = $DB->get_records('local_aiconnect_token_registry', ['token_prefix' => $prefix], 'id DESC', 'id, revoked_at', 0, 1);
        if (!$rows) {
            return false;
        }
        $row = reset($rows);
        return $row->revoked_at !== null;
    }

    /**
     * The user rejects a pending action.
     *
     * @param string $confid Confirmation id.
     * @param int    $userid Logged-in user (must own the action).
     * @return bool True when the action moved to rejected.
     */
    public static function reject(string $confid, int $userid): bool {
        global $DB;
        $claim = bin2hex(random_bytes(16));
        $DB->execute(
            'UPDATE {' . self::TABLE . "} SET status = 'rejected', claim = ?, timedecided = ?, decidedby = ?
              WHERE confid = ? AND userid = ? AND status = 'pending'",
            [$claim, time(), $userid, $confid, $userid]
        );
        $action = self::get_action($confid);
        if (!$action || (int) $action->userid !== $userid || $action->status !== 'rejected' || (string) $action->claim !== $claim) {
            return false;
        }
        self::log('rejected', $confid, $userid, $action->tool, $action->clientid);
        \local_aiconnect\event\ai_action_rejected::create_from_action($action)->trigger();
        return true;
    }

    // Status.

    /**
     * Status of an action for its owner (GET status_url). Marks the result as collected.
     *
     * @param string $confid Confirmation id.
     * @param int    $userid Token owner.
     * @return array|null Null when unknown for this user.
     */
    public static function status_for(string $confid, int $userid): ?array {
        self::sweep();
        $action = self::get_action($confid);
        if (!$action || (int) $action->userid !== $userid) {
            return null;
        }
        $out = self::public_status($action);
        if (in_array($action->status, ['executed', 'failed'], true)) {
            $out['result'] = json_decode((string) $action->resultjson, true);
            self::mark_delivered($action);
        }
        return $out;
    }

    /**
     * Public fields of an action (no arguments, no result).
     *
     * @param \stdClass $action Row.
     * @return array
     */
    public static function public_status(\stdClass $action): array {
        return [
            'confirmation_id' => $action->confid,
            'status'          => $action->status,
            'tool'            => $action->tool,
            'risk'            => $action->risk,
            'summary'         => (string) $action->summary,
            'created_at'      => self::iso((int) $action->timecreated),
            'expires_at'      => self::iso((int) $action->expiresat),
            'decided_at'      => $action->timedecided ? self::iso((int) $action->timedecided) : null,
            'executed_at'     => $action->timeexecuted ? self::iso((int) $action->timeexecuted) : null,
            'flow'            => self::FLOW,
        ];
    }

    /**
     * Remember that the agent collected the result; redact secrets after that first delivery.
     *
     * @param \stdClass $action Row.
     */
    private static function mark_delivered(\stdClass $action): void {
        global $DB;
        if (!in_array($action->status, ['executed', 'failed'], true)) {
            return;
        }
        $update = (object) ['id' => $action->id];
        if (empty($action->timedelivered)) {
            $update->timedelivered = time();
        }
        $result = json_decode((string) $action->resultjson, true);
        $changed = false;
        if (is_array($result)) {
            $result = self::redact($result, $changed);
        }
        if ($changed) {
            $update->resultjson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (count((array) $update) > 1) {
            $DB->update_record(self::TABLE, $update);
        }
    }

    /**
     * Replace values of secret-looking keys.
     *
     * @param array $data    Data.
     * @param bool  $changed Set to true when something was replaced.
     * @return array
     */
    private static function redact(array $data, bool &$changed): array {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::redact($v, $changed);
            } else if (
                is_string($k) && preg_match('/(password|secret|token)$/i', $k) && is_string($v) && $v !== ''
                    && strpos($v, '[redacted') !== 0
            ) {
                $data[$k] = '[redacted after first delivery]';
                $changed = true;
            }
        }
        return $data;
    }

    // Store.

    /**
     * Create a pending action.
     *
     * @param int    $userid  User.
     * @param string $full    Tool.
     * @param array  $args    Arguments (canonical).
     * @param string $hash    Arguments hash.
     * @param array  $conf    Declaration.
     * @param array  $preview Preview (without summary/details).
     * @param string $summary User sentence.
     * @param array  $details Plain lines.
     * @param string $lang    Language the texts were built in.
     * @param array  $context Request context.
     * @return \stdClass Row.
     */
    private static function create_pending(
        int $userid,
        string $full,
        array $args,
        string $hash,
        array $conf,
        array $preview,
        string $summary,
        array $details,
        string $lang,
        array $context
    ): \stdClass {
        global $DB;
        $now = time();
        $clientid = (string) ($context['clientid'] ?? '');
        $row = (object) [
            'confid'      => 'conf_' . bin2hex(random_bytes(16)),
            'userid'      => $userid,
            'tool'        => $full,
            'argsjson'    => json_encode(self::ksort_deep($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'argshash'    => $hash,
            'clientkey'   => self::client_key($clientid),
            'clientid'    => \core_text::substr($clientid, 0, 100),
            'scope'       => \core_text::substr((string) ($context['scope'] ?? ''), 0, 255),
            'tokenprefix' => \core_text::substr((string) ($context['tokenprefix'] ?? ''), 0, 16),
            'risk'        => $conf['risk'],
            'summary'     => $summary,
            'details'     => json_encode($details, JSON_UNESCAPED_UNICODE),
            'previewjson' => json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'lang'        => $lang,
            'status'      => 'pending',
            'claim'       => '',
            'timecreated' => $now,
            'expiresat'   => $now + self::ttl(),
        ];
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Load an action by confirmation id.
     *
     * @param string $confid Id.
     * @return \stdClass|null
     */
    public static function get_action(string $confid): ?\stdClass {
        global $DB;
        if (!preg_match('/^conf_[a-f0-9]{32}$/', $confid)) {
            return null;
        }
        $row = $DB->get_record(self::TABLE, ['confid' => $confid]);
        return $row ?: null;
    }

    /**
     * Actions of a user.
     *
     * @param int         $userid User.
     * @param string|null $status Status filter or null for all.
     * @param int         $limit  Max rows.
     * @return array<int,\stdClass>
     */
    public static function list_actions(int $userid, ?string $status = 'pending', int $limit = 50): array {
        global $DB;
        self::sweep();
        $params = ['userid' => $userid];
        if ($status !== null) {
            $params['status'] = $status;
        }
        return array_values($DB->get_records(self::TABLE, $params, 'timecreated DESC', '*', 0, $limit));
    }

    /**
     * Number of actions waiting for the user.
     *
     * @param int $userid User.
     * @return int
     */
    public static function count_pending(int $userid): int {
        global $DB;
        return $DB->count_records_select(self::TABLE, "userid = ? AND status = 'pending' AND expiresat > ?", [$userid, time()]);
    }

    /**
     * Pending action for exactly this call.
     *
     * @param int    $userid    User.
     * @param string $full      Tool.
     * @param string $hash      Arguments hash.
     * @param string $clientkey Client key.
     * @return \stdClass|null
     */
    private static function find_pending(int $userid, string $full, string $hash, string $clientkey): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_select(
            self::TABLE,
            "userid = ? AND tool = ? AND argshash = ? AND clientkey = ? AND status = 'pending' AND expiresat > ?",
            [$userid, $full, $hash, $clientkey, time()],
            'timecreated DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * The same call ran just now (≤ 120 s), or recently and its result was not collected.
     *
     * @param int    $userid    User.
     * @param string $full      Tool.
     * @param string $hash      Arguments hash.
     * @param string $clientkey Client key.
     * @return \stdClass|null
     */
    private static function find_recent_executed(int $userid, string $full, string $hash, string $clientkey): ?\stdClass {
        global $DB;
        $now = time();
        $rows = $DB->get_records_select(
            self::TABLE,
            "userid = ? AND tool = ? AND argshash = ? AND clientkey = ? AND status IN ('executed', 'failed')
             AND (timeexecuted >= ? OR (timeexecuted >= ? AND timedelivered IS NULL))",
            [$userid, $full, $hash, $clientkey, $now - self::RECENT_WINDOW, $now - self::DEFAULT_TTL],
            'timeexecuted DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Move pending actions past their expiry to `expired`.
     */
    public static function sweep(): void {
        global $DB;
        $now = time();
        $rows = $DB->get_records_select(
            self::TABLE,
            "status = 'pending' AND expiresat <= ?",
            [$now],
            '',
            'id, confid, userid, tool, clientid',
            0,
            200
        );
        foreach ($rows as $row) {
            $DB->execute('UPDATE {' . self::TABLE . "} SET status = 'expired' WHERE id = ? AND status = 'pending'", [$row->id]);
            self::log('expired', $row->confid, (int) $row->userid, $row->tool, (string) $row->clientid);
        }
    }

    /**
     * Scheduled cleanup: expire, then drop decided actions and log rows older than KEEP_DAYS.
     */
    public static function cleanup(): void {
        global $DB;
        self::sweep();
        $cut = time() - self::KEEP_DAYS * DAYSECS;
        $DB->delete_records_select(self::TABLE, "status <> 'pending' AND status <> 'executing' AND timecreated < ?", [$cut]);
        $DB->delete_records_select(self::LOG, 'timecreated < ?', [$cut]);
    }

    // Grants.

    /**
     * May users let an agent run a gated tool without asking (site switch)?
     *
     * @return bool
     */
    public static function autonomy_allowed(): bool {
        $v = get_config('local_aiconnect', 'confirmation_autonomy');
        return $v === false || $v === null || $v === '' || (bool) $v;
    }

    /**
     * Grant autonomy for user + tool + client.
     *
     * @param int    $userid   User.
     * @param string $tool     Tool.
     * @param string $clientid Client.
     */
    public static function grant(int $userid, string $tool, string $clientid): void {
        global $DB;
        if (self::find_grant($userid, $tool, $clientid)) {
            return;
        }
        $id = $DB->insert_record(self::GRANTS, (object) [
            'userid' => $userid, 'tool' => $tool, 'clientid' => $clientid, 'via' => 'confirm_page', 'timecreated' => time(),
        ]);
        self::log('autonomy_granted', '', $userid, $tool, $clientid, ['grant_id' => (int) $id]);
    }

    /**
     * Remove one of the user's grants.
     *
     * @param int $grantid Grant id.
     * @param int $userid  Owner.
     * @return bool
     */
    public static function revoke_grant(int $grantid, int $userid): bool {
        global $DB;
        $g = $DB->get_record(self::GRANTS, ['id' => $grantid, 'userid' => $userid]);
        if (!$g) {
            return false;
        }
        $DB->delete_records(self::GRANTS, ['id' => $grantid]);
        self::log('autonomy_revoked', '', $userid, $g->tool, $g->clientid, ['grant_id' => $grantid]);
        return true;
    }

    /**
     * Find a grant.
     *
     * @param int    $userid   User.
     * @param string $tool     Tool.
     * @param string $clientid Client.
     * @return \stdClass|null
     */
    public static function find_grant(int $userid, string $tool, string $clientid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::GRANTS, ['userid' => $userid, 'tool' => $tool, 'clientid' => $clientid]);
        return $row ?: null;
    }

    /**
     * Grants of a user.
     *
     * @param int $userid User.
     * @return array<int,\stdClass>
     */
    public static function list_grants(int $userid): array {
        global $DB;
        return array_values($DB->get_records(self::GRANTS, ['userid' => $userid], 'timecreated DESC'));
    }

    // Log.

    /**
     * Append an audit row.
     *
     * @param string $event    Event name.
     * @param string $confid   Confirmation id ('' when none).
     * @param int    $userid   Owner of the action.
     * @param string $tool     Tool.
     * @param string $clientid Client.
     * @param array  $detail   Extra (no secrets).
     */
    public static function log(
        string $event,
        string $confid,
        int $userid,
        string $tool,
        string $clientid = '',
        array $detail = []
    ): void {
        global $DB, $USER;
        try {
            $DB->insert_record(self::LOG, (object) [
                'confid'      => $confid,
                'event'       => $event,
                'userid'      => $userid,
                'actorid'     => isset($USER->id) ? (int) $USER->id : 0,
                'tool'        => \core_text::substr($tool, 0, 100),
                'clientid'    => \core_text::substr($clientid, 0, 100),
                'ip'          => \core_text::substr((string) getremoteaddr(), 0, 45),
                'detail'      => $detail ? json_encode($detail) : null,
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            debugging('local_aiconnect confirmation log failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Recent log rows of a user.
     *
     * @param int $userid User.
     * @param int $limit  Max rows.
     * @return array<int,\stdClass>
     */
    public static function list_log(int $userid, int $limit = 30): array {
        global $DB;
        return array_values($DB->get_records(self::LOG, ['userid' => $userid], 'timecreated DESC, id DESC', '*', 0, $limit));
    }

    // Helpers.

    /**
     * Pending-action lifetime in seconds (site setting, 60 s – 24 h).
     *
     * @return int
     */
    public static function ttl(): int {
        $v = (int) get_config('local_aiconnect', 'confirmation_ttl');
        if ($v <= 0) {
            return self::DEFAULT_TTL;
        }
        return max(60, min(DAYSECS, $v));
    }

    /**
     * Client key stored with an action.
     *
     * @param string $clientid OAuth client id.
     * @return string
     */
    private static function client_key(string $clientid): string {
        return 'rest:' . ($clientid === '' ? 'unknown' : \core_text::substr($clientid, 0, 100));
    }

    /**
     * Language to address a user in.
     *
     * @param int $userid User.
     * @return string
     */
    public static function user_lang(int $userid): string {
        global $DB, $CFG;
        $lang = (string) $DB->get_field('user', 'lang', ['id' => $userid]);
        $sm = get_string_manager();
        if ($lang !== '' && $sm->translation_exists($lang, false)) {
            return $lang;
        }
        return !empty($CFG->lang) ? (string) $CFG->lang : 'en';
    }

    /**
     * Confirm page URL (this site, the user's own session).
     *
     * @param string $confid Id.
     * @return string
     */
    public static function confirm_url(string $confid): string {
        return (new \moodle_url('/local/aiconnect/aiactions.php', ['id' => $confid]))->out(false);
    }

    /**
     * Status URL (Bearer token of the same user).
     *
     * @param string $confid Id.
     * @return string
     */
    public static function status_url(string $confid): string {
        return self::api_base() . '/confirmations.php?id=' . rawurlencode($confid);
    }

    /**
     * Public base of the plugin's API (honours public_base_url).
     *
     * @return string
     */
    public static function api_base(): string {
        $cfg = (string) get_config('local_aiconnect', 'public_base_url');
        $base = $cfg !== '' ? rtrim($cfg, '/') : rtrim((new \moodle_url('/'))->out(false), '/');
        return $base . '/local/aiconnect';
    }

    /**
     * Unix time → ISO 8601 UTC.
     *
     * @param int $ts Time.
     * @return string|null
     */
    private static function iso(int $ts): ?string {
        return $ts > 0 ? gmdate('c', $ts) : null;
    }

    /**
     * Manifest top-level block (only when at least one tool declares confirmation).
     *
     * @param array $tools Manifest tools.
     * @return array|null
     */
    public static function manifest_block(array $tools): ?array {
        $names = [];
        foreach ($tools as $t) {
            if (!empty($t['confirmation'])) {
                $names[] = $t['name'];
            }
        }
        if (!$names) {
            return null;
        }
        return [
            'flow'                => self::FLOW,
            'pending_status_code' => 202,
            'error_code'          => 'confirmation_required',
            'status_url_template' => self::api_base() . '/confirmations.php?id={confirmation_id}',
            'confirm_page'        => (new \moodle_url('/local/aiconnect/aiactions.php'))->out(false),
            'confirmed_by'        => 'user',
            'autonomy'            => self::autonomy_allowed(),
            'tools'               => $names,
        ];
    }

    /** @var string Instructions for agents (manifest `instructions`). */
    public const INSTRUCTIONS = 'Some tools change or remove data (delete, suspend, reset, change roles or site settings).'
        . ' They are marked "confirmation" in this manifest. Calling one does NOT execute it: you get HTTP 202 with'
        . ' status=pending_confirmation, a summary and confirm_url. Show the user the summary and the link, say nothing'
        . ' has happened yet, and never repeat the call on your own. Only the user can confirm, signed in to Moodle.'
        . ' After they confirm, send the same call with confirmation_id (or GET status_url) to read the result.';
}
