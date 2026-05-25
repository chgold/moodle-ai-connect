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
 * Language strings for local_aiconnect (English).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']           = 'AI Connect';
$string['aiconnect:view']       = 'View AI Connect info page';
$string['aiconnect:use']        = 'Use AI Connect (generate tokens, use free tools)';
$string['aiconnect:usepro']     = 'Use AI Connect Pro tools (write operations)';
$string['settings_heading']     = 'AI Connect Settings';
$string['ratelimit_minute']     = 'Rate limit per minute';
$string['ratelimit_minute_desc']= 'Maximum number of API requests allowed per minute per access token.';
$string['ratelimit_hour']       = 'Rate limit per hour';
$string['ratelimit_hour_desc']  = 'Maximum number of API requests allowed per hour per access token.';
$string['public_base_url']      = 'Public base URL';
$string['public_base_url_desc'] = 'Override auto-detected URL when behind a reverse proxy. Leave empty to use $CFG->wwwroot.';
$string['allowed_origins']      = 'Allowed CORS origins';
$string['allowed_origins_desc'] = 'Comma-separated list of origins permitted in Access-Control-Allow-Origin. Use * to allow any origin (not recommended for production). Default: empty (CORS disabled).';
$string['enforce_https']        = 'Enforce HTTPS for redirect URIs';
$string['enforce_https_desc']   = 'When enabled, OAuth redirect_uri values must use https://. Automatically enforced whenever $CFG->wwwroot is https.';
$string['privacy:metadata:local_aiconnect_clients']            = 'Registered OAuth2 clients (AI agents).';
$string['privacy:metadata:local_aiconnect_clients:client_id']  = 'Stable identifier of the OAuth client.';
$string['privacy:metadata:local_aiconnect_codes']              = 'Short-lived OAuth2 authorization codes.';
$string['privacy:metadata:local_aiconnect_codes:userid']       = 'The Moodle user who granted consent.';
$string['privacy:metadata:local_aiconnect_tokens']             = 'OAuth2 access and refresh tokens.';
$string['privacy:metadata:local_aiconnect_tokens:userid']      = 'The Moodle user the token authenticates as.';
$string['privacy:metadata:local_aiconnect_tokens:scope']       = 'Scopes (permissions) granted to the token.';
$string['privacy:metadata:local_aiconnect_ratelimits']         = 'Per-token rate-limit counters.';
$string['task_cleanup_expired'] = 'Cleanup expired AI Connect tokens';
$string['admin_tokens_heading'] = 'AI Connect Token Registry';
$string['privacy:metadata:local_aiconnect_token_registry']                = 'Audit registry of every issued access token (prefix only).';
$string['privacy:metadata:local_aiconnect_token_registry:user_id']        = 'The Moodle user the registered token authenticated as.';
$string['privacy:metadata:local_aiconnect_token_registry:ip_address']     = 'Client IP address recorded at the moment of issuance.';
$string['privacy:metadata:local_aiconnect_token_registry:last_used_ip']   = 'Client IP address of the most recent successful API call.';
$string['privacy:metadata:local_aiconnect_token_registry:last_used_ua']   = 'User-Agent string of the most recent successful API call.';

// My Tokens user page.
$string['mytokens_heading']            = 'My AI Connect Tokens';
$string['mytokens_none']               = 'You have no tokens matching this filter.';
$string['mytokens_filter_all']         = 'All';
$string['mytokens_filter_active']      = 'Active';
$string['mytokens_filter_unused']      = 'Unused';
$string['mytokens_filter_inactive']    = 'Inactive';
$string['mytokens_filter_expired']     = 'Expired';
$string['mytokens_filter_revoked']     = 'Revoked';
$string['mytokens_col_prefix']         = 'Token prefix';
$string['mytokens_col_source']         = 'Source';
$string['mytokens_col_scope']          = 'Scope';
$string['mytokens_col_issued']         = 'Issued';
$string['mytokens_col_lastused']       = 'Last used';
$string['mytokens_col_lastip']         = 'Last IP';
$string['mytokens_col_lastua']         = 'Last client';
$string['mytokens_col_state']          = 'State';
$string['mytokens_col_actions']        = 'Actions';
$string['mytokens_revoke']             = 'Revoke';
$string['mytokens_revoke_all_unused']  = 'Revoke all unused';
$string['mytokens_revoke_all_inactive']= 'Revoke all inactive';
$string['mytokens_revoke_all']         = 'Revoke all (DANGER)';
$string['mytokens_revoke_confirm']     = 'Are you sure you want to revoke this token? This cannot be undone.';
$string['mytokens_revoke_all_confirm'] = 'Are you sure you want to revoke ALL selected tokens? This cannot be undone.';
$string['mytokens_revoked_ok']         = 'Token revoked successfully.';
$string['mytokens_revoked_bulk_ok']    = '{$a} token(s) revoked successfully.';
$string['mytokens_state_active']       = '🟢 Active';
$string['mytokens_state_unused']       = '🟡 Unused';
$string['mytokens_state_inactive']     = '🟠 Inactive';
$string['mytokens_state_expired']      = '⚫ Expired';
$string['mytokens_state_revoked']      = '🔴 Revoked';
$string['mytokens_never']              = 'Never';

// Admin token page extras.
$string['admin_tokens_filter_unused']   = 'Unused';
$string['admin_tokens_filter_inactive'] = 'Inactive';
$string['admin_tokens_bulk_unused']     = 'Revoke all unused';
$string['admin_tokens_bulk_inactive']   = 'Revoke all inactive';
$string['admin_tokens_bulk_all']        = 'Revoke ALL active (DANGER)';
$string['admin_tokens_col_lastip']      = 'Last IP';
$string['admin_tokens_col_lastua']      = 'Last UA';
