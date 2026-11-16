# Changelog

All notable changes to `local_aiconnect` are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
semantic versioning.

## [2.2.5] — 2026-11-16

### Privacy
- **Privacy API now covers every table with personal data.** The provider
  also declares, exports and deletes `local_aiconnect_token_registry`
  (`user_id`, issue IP address, last-used IP address and user agent,
  `revoked_by`) and `local_aiconnect_blocked` (`userid`, `blockedby`), and
  declares `local_aiconnect_ratelimits`. Deleting a user also removes the
  rate-limit counters of that user's tokens. When the deleted user is an admin
  who revoked tokens or blocked users, those fields are set to NULL instead of
  deleting the other user's record. Token values and prefixes are never
  exported.

### Documentation
- README rewritten for the current release: the 35 read-only tools, the
  paid add-ons as separate plugins, the full database schema and support
  links. Added `LICENSE` (GPL v3), `SECURITY.md` and GitHub issue templates.

### Tests
- `tests/privacy_provider_test.php`: metadata, contexts, user list, export and
  delete (single user, user list, whole context).

## [2.2.4] — 2026-11-15

### Security
- **Fail-closed scope (SEC-02, audit M1).** A tool registered without
  `required_scope` is now treated as `write` instead of `read`, so a read-only
  token can never reach it because a declaration was forgotten (all current
  tools already declare one). `get_tool_scope()` answers `write` for unknown
  tools, and a request context without a scope grants nothing.

### Fixed
- The lazy daily token cleanup printed its summary line (mtrace) in front of
  the JSON body of the API request that triggered it, breaking that response.
  It now reports only on the CLI.

### Tests
- `tests/module_base_test.php`: `test_undeclared_scope_fails_closed`.

## [2.2.2] — 2026-11-09

### Security
- **Security: SEC-00 permission ceiling for the H5P and BigBlueButton tools** — tools now return no more than the acting user can see in Moodle itself.
  - All `hvp.*` / `bigbluebutton.*` list/get tools apply the `require_login($course, false, $cm)` equivalent: course access with an *active* enrolment, `cm_info::uservisible` (hidden, stealth and availability-restricted activities are filtered from lists and answer "not found" on direct lookup) and `mod/{module}:view`.
  - `hvp.getContentResults` / `getUserAttempts` / `getAttemptDetails`: own attempts only, unless the user holds `mod/h5pactivity:reviewattempts` in that activity (Moodle's `manager::get_report()` rule incl. tracking + review mode); reviewers are limited to their groups in separate-groups mode. Foreign attempt ids answer "not found". `hvp.getCourseAnalytics` aggregates only what the user could review.
  - `hvp.getContentByType`: only content-bank items in contexts where the user has `moodle/contentbank:access` (plus course/category visibility) and that are public, own, or viewable via `moodle/contentbank:viewunlistedcontent`.
  - `hvp.getContentParameters`: raw parameters (contain the answers) now require edit rights on the owning context (`moodle/course:manageactivities` / `moodle/contentbank:manageanycontent`) instead of a site-wide gate.
  - `bigbluebutton.getMeetingAttendanceReport`: requires `report/participation:view` or `report/log:view` in the course (BBB has no own log capability; these reports are where Moodle shows other users' BBB activity), separate groups respected; everyone else is refused.
  - `bigbluebutton.getJoinUrl`: requires `instance::can_join()` (`mod/bigbluebuttonbn:join` + group membership, never recording-only), validates `groupid` like the native `get_join_url` web service and honours `openingtime`/`closingtime`. Returns Moodle's `bbb_view.php?action=join` URL so wait-for-moderator, user limit and join logging stay enforced by Moodle.
  - `bigbluebutton.listRecordings` / `getRecordingDetails` / `getRecordingPlaybackUrl`: users without `mod/bigbluebuttonbn:managerecordings` see only processed, non-headless recordings.

## [2.2.1] — 2026-11-09

### Security
- **RFC 7009 token revocation:** `oauth.php?action=revoke` (manifest `auth.revocation_url`) now takes `token` (access OR refresh) + optional `token_type_hint` as form-urlencoded or JSON, with no authentication. Either half revokes the whole access/refresh pair immediately and syncs the token registry (admin list + My Tokens). Unknown or already-revoked tokens return 200; a missing `token` returns 400 `invalid_request`. Previously only a Bearer header was honoured and refresh tokens survived revocation.
- Revoke endpoint per-IP rate limit raised to the token endpoint's budget (20/min, 200/h).

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
- Bootstrap: a hardcoded absolute `config.php` path →
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
