# Changelog

All notable changes to `local_aiconnect` are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
semantic versioning.

## [2.2.0] — 2026-07-03

### Added
- **Prompt generator:** `generate_token` endpoint now returns a complete AI agent prompt with connection instructions, tool list, and token refresh details (Servio Protocol format).

### Changed
- **Servio Protocol:** Token prefixes aligned to unified standard (`mdl_` → `xfa_`, `mdlr_` → `xfr_`).

## [1.1.0] — 2026-05-10

Production hardening release. Closes 12 audit findings.

### Added
- `db/install.xml` — declarative XMLDB schema with proper indexes
  (`client_id_idx`, `code_idx`, `token_idx`, `tokentype_revoked_idx`,
  `token_window_idx`, etc.).
- `db/upgrade.php` — `xmldb_local_aiconnect_upgrade` adds the new indexes on
  existing installations.
- `db/uninstall.php` — drops every plugin table and clears `config_plugins`.
- `db/tasks.php` + `classes/task/cleanup_expired_tokens.php` — daily cron job
  that purges expired tokens, consumed codes and stale rate-limit rows.
- `classes/lib/http.php` — CORS, JSON exit, OAuth error envelope, and
  `redirect_uri` scheme validation helpers.
- `classes/privacy/provider.php` — full GDPR metadata, export and delete
  implementation (system context).
- Admin settings: `allowed_origins` (CSV whitelist), `enforce_https`.
- PHPUnit test suite (`tests/manifest_test.php`, `oauth_test.php`,
  `tools_test.php`, `auth_test.php`, `rate_limit_test.php`) — 32 tests, 79
  assertions, all green.
- `cli/seed_test_data.php` — idempotent seeder that creates 3 courses, 3
  teachers, 5 students and 9 assignments for QA.
- `README.md`, `CHANGELOG.md`, `TEST_CREDENTIALS.md`, and full PHPDoc on
  every public class and method (Moodle PHPCS-compliant).

### Changed
- Bootstrap: `require_once('/workspace/platforms/moodle/config.php')` →
  portable `require(__DIR__ . '/../../config.php')` everywhere.
- Default CORS replaced from `Access-Control-Allow-Origin: *` to the
  configurable whitelist (disabled by default).
- Every multi-row DB write is now wrapped in
  `$DB->start_delegated_transaction()` with proper rollback.
- All tool parameters validated through Moodle's `clean_param()` /
  `PARAM_*` (replaces previous `PARAM_RAW`).
- POST `/token` and `/generate_token` enforce `require_sesskey()` (CSRF).
- `redirect_uri` must use `https://` (override per setting / dev mode).
- PKCE verification uses `hash_equals` (timing-safe) and emits proper
  base64url for the S256 challenge comparison.
- `fullname()` lookups go through `\core_user::get_user()` so the privacy
  fields are always present.
- Tool dispatcher catches `invalid_parameter_exception` separately so
  parameter errors don't trigger `debugging()` noise.

### Fixed
- `enrol_get_plugin('manual')->get_instance_by_courseid()` → replaced with
  the supported `$DB->get_record('enrol', …)` lookup (the previous call
  raised "Call to undefined method" on Moodle 4.3+).
- `tools.php` shim now resolves the tool name from
  `parse_url(REQUEST_URI, PHP_URL_PATH)` rather than the raw URI.

### Security
- HTTPS enforcement on OAuth `redirect_uri`.
- CSRF tokens (`sesskey`) on every state-changing POST.
- Per-token rate limit persisted to DB (was previously in-memory only).
- CORS whitelist (was `*`).

## [1.0.0] — 2026-04-19

Initial release.
