# Goldnat AI Connect for Moodle (`local_aiconnect`)

> Lets AI agents (Claude, ChatGPT, Gemini, Copilot, Grok, Perplexity, Meta AI,
> DeepSeek, …) read your Moodle site over OAuth 2.0 + PKCE, **as the user who
> approved them** and with that user's Moodle permissions. Tools are published
> in a JSON manifest (the open [Servio](https://github.com/chgold/servio)
> protocol), so no per-agent setup is needed.

- **Component:** `local_aiconnect`
- **Type:** local plugin
- **Compatibility:** Moodle **4.3+** (`$plugin->requires = 2023100900`). Tested on Moodle 4.4 (MariaDB).
- **Maturity:** `MATURITY_STABLE`
- **Release:** `2.2.6`
- **License:** GNU GPL v3 or later (see `LICENSE`)
- **Edition:** free. All tools in this plugin are **read-only**.

This plugin is the reverse of Moodle's built-in AI subsystem: it does not add
AI features inside Moodle. It gives an external assistant, chosen by the user,
controlled read access to Moodle.

---

## Tools (35, all `read` scope)

Every call runs as the token owner and goes through Moodle's own capability
and visibility checks (`require_login`-equivalent, `cm_info::uservisible`,
module `:view` capabilities). A student's agent sees that student's courses
and grades, not anyone else's.

| Module | Tools |
|--------|-------|
| `moodle` (core, 6) | `getCourses`, `getCourse`, `getEnrollments`, `getAssignments`, `getGrades`, `getUserProfile` |
| `content` (7) | `getCourseModules`, `getModuleContent`, `getCourseFiles`, `downloadCourseFile`, `getForumDiscussions`, `getForumPosts`, `searchForumPosts` |
| `bigbluebutton` (10) — only if `mod_bigbluebuttonbn` is installed | `listCourseMeetings`, `searchMeetings`, `getMeetingDetails`, `getMeetingStatus`, `getMeetingParticipants`, `getJoinUrl`, `getMeetingAttendanceReport`, `listRecordings`, `getRecordingDetails`, `getRecordingPlaybackUrl` |
| `hvp` (12) — only if the H5P activity module (`mod_h5pactivity`) is enabled | `getContentByCourse`, `getContent`, `searchContent`, `getContentByType`, `listContentTypes`, `getContentParameters`, `getContentEmbedCode`, `getContentResults`, `getUserAttempts`, `getAttemptDetails`, `getCourseAnalytics`, `exportContent` |

Tools that depend on a missing activity module are left out of the manifest
entirely, and a direct call returns `424 Failed Dependency`.

### Paid add-ons (separate plugins, yearly licence)

Write tools live in separate plugins that require this one:

| Plugin | Component | For |
|--------|-----------|-----|
| Goldnat AI Connect Teacher | `local_aiconnectteacher` | gradebook, assignments, enrolments, course content |
| Goldnat AI Connect Student | `local_aiconnectstudent` | own deadlines, submissions, feedback, profile |
| Goldnat AI Connect Admin | `local_aiconnectadmin` | site administration tasks |

Details: <https://plugins.goldnat.ai/moodle>

---

## Installation

1. Copy or symlink the plugin into `local/aiconnect`:

   ```bash
   ln -s /path/to/moodle-ai-connect /var/www/moodle/local/aiconnect
   ```

2. Visit `/admin/index.php` as a site administrator — Moodle picks up the
   plugin and runs `db/install.xml`, `db/install.php` and `db/upgrade.php`
   automatically.

3. Configure under **Site administration → Plugins → Local plugins → AI Connect**.

   | Setting | Default | Notes |
   |---------|---------|-------|
   | `public_base_url` | empty | Override auto-detected URL when behind a reverse proxy |
   | `allowed_origins` | empty | CSV CORS whitelist; `*` allows any origin |
   | `enforce_https` | `0` | Force `https://` for OAuth `redirect_uri` |
   | `ratelimit_minute` | `50` | Requests per minute per token |
   | `ratelimit_hour` | `1000` | Requests per hour per token |

---

## Capabilities & roles

Defined in `db/access.php`:

| Capability | Default archetypes |
|------------|--------------------|
| `local/aiconnect:view` | user, student, teacher |
| `local/aiconnect:use` | teacher, editingteacher, manager |
| `local/aiconnect:usepro` | editingteacher, manager |

---

## Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| `GET`  | `/local/aiconnect/manifest.php` | Servio manifest (public) |
| `GET`  | `/local/aiconnect/info.php` | Human-readable info & token generator |
| `GET`/`POST` | `/local/aiconnect/oauth.php?action=authorize` | OAuth 2 consent |
| `POST` | `/local/aiconnect/oauth.php?action=token` | Token grant |
| `POST` | `/local/aiconnect/oauth.php?action=revoke` | RFC 7009 token revocation — no auth; `token` (access or refresh, revokes the whole pair) + optional `token_type_hint`; always 200, 400 only when `token` is missing. Advertised in the manifest as `auth.revocation_url` |
| `POST` | `/local/aiconnect/oauth.php?action=generate_token` | Helper for logged-in admins |
| `POST` | `/local/aiconnect/v1/tools/{tool}` | Execute a tool |
| `POST` | `/local/aiconnect/tools.php` | Same, with `?tool=` query parameter |
| `GET`  | `/local/aiconnect/confirmations.php?id={confirmation_id}` | Status (and, once, the result) of an action waiting for the user — Bearer token of the same user |
| `GET`/`POST` | `/local/aiconnect/aiactions.php` | **AI actions** page: the signed-in user confirms or rejects what an agent asked (session + `sesskey`; a request with an `Authorization` header is refused) |

---

## Quickstart with `curl`

```bash
# 1. Public manifest
curl -s http://localhost:8088/local/aiconnect/manifest.php | jq .

# 2. Generate a token (must be logged in as a user with local/aiconnect:use).
#    The info.php page exposes a Generate Token button that does the same.

# 3. List enrolled courses
TOKEN="mdl_xxxxxxxxxxxxxxxx"
curl -s -X POST "http://localhost:8088/local/aiconnect/v1/tools/moodle.getCourses" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"limit": 10}' | jq .

# 4. Get current user profile
curl -s -X POST "http://localhost:8088/local/aiconnect/v1/tools/moodle.getUserProfile" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{}' | jq .

```

---

## Database schema

Defined declaratively in `db/install.xml`.

```
local_aiconnect_clients        OAuth2 clients (one per registered AI agent)
  ├─ client_id     (unique)
  ├─ client_name
  ├─ redirect_uris (newline separated)
  ├─ scopes
  └─ timecreated

local_aiconnect_codes          Short-lived PKCE authorization codes (TTL=10m)
  ├─ code          (unique)
  ├─ client_id
  ├─ userid        (FK → mdl_user.id)
  ├─ scope
  ├─ code_challenge
  ├─ redirect_uri
  ├─ expiry
  └─ used

local_aiconnect_tokens         Access (TTL=1h) and refresh (TTL=30d) tokens
  ├─ token         (unique)
  ├─ tokentype     (access|refresh)
  ├─ client_id
  ├─ userid        (FK → mdl_user.id)
  ├─ scope
  ├─ parenttoken
  ├─ expiry
  ├─ revoked
  └─ timecreated

local_aiconnect_ratelimits     Per-token rate-limit counters
  ├─ token
  ├─ windowtype    (minute|hour)
  ├─ windowstart
  └─ requestcount
  UNIQUE(token, windowtype)

local_aiconnect_token_registry Audit list of issued tokens (admin list + "My AI tokens")
  ├─ token_prefix  (first 16 chars only — never the full token)
  ├─ user_id       (FK → mdl_user.id)
  ├─ client_id, scope, source
  ├─ issued_at, expires_at, last_used_at
  ├─ ip_address, last_used_ip, last_used_ua
  └─ revoked_at, revoked_by

local_aiconnect_blocked        Users an admin blocked from the API
  ├─ userid
  ├─ blockedby
  └─ timecreated
```

A scheduled task (`\local_aiconnect\task\cleanup_expired_tokens`) runs daily
and removes tokens older than 7 days, consumed/expired codes, and stale
rate-limit rows.

---

## Security model

| Concern | Implementation |
|---------|----------------|
| Bootstrap | `require(__DIR__ . '/../../config.php')` — portable, no hardcoded paths |
| OAuth flow | Authorization-code + **mandatory PKCE S256** (RFC 7636) |
| CSRF | `require_sesskey()` on every POST that mutates Moodle state |
| Token format | `bin2hex(random_bytes(32))` (256-bit entropy) |
| HTTPS | `redirect_uri` must use `https://` unless Moodle itself is on `http://` (configurable via `enforce_https`) |
| CORS | Disabled by default — whitelist origins via `allowed_origins` |
| Transactions | All multi-row writes use `$DB->start_delegated_transaction()` |
| Rate limit | DB-backed, per-token, minute + hour windows |
| Input validation | Moodle `PARAM_*` cleaners on every tool parameter |
| Write tools (add-ons) | Refused unless the token scope contains `write`; a tool without a declared scope is treated as `write` (fail-closed) |
| Permission ceiling (SEC-00) | Security: H5P / BigBlueButton tools return only what the user sees natively — hidden/restricted activities filtered (`cm_info::uservisible`), attempts own-only without `mod/h5pactivity:reviewattempts`, BBB attendance needs course report capability, join honours `:join`, groups and opening/closing time (2.2.2) |
| Privacy | `\local_aiconnect\privacy\provider` declares, exports and deletes every table with personal data: codes, tokens, rate-limit counters, the token registry (incl. IP address and user agent) and the block list (2.2.5) |
| External services | None, unless a paid add-on licence key is entered: then the key and the site URL are checked against `goldnat.ai`. No user data leaves the site |
| User confirmation (2.2.7) | Destructive tools of the add-ons (`confirmation` in the manifest) answer HTTP 202 `confirmation_required` and run only after the token's owner confirms on **AI actions** — bound to user + tool + SHA-256 of the arguments + client, 15-minute expiry, single use, executed once as that user; re-sending with `confirmation_id` only reports. Flow `user-confirm-v1` (Servio §6.5) |
| Cleanup | Daily scheduled task purges expired tokens & codes |
| Uninstall | `db/uninstall.php` drops every plugin table cleanly |

---

## Development

```bash
# Lint
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;

# PHPCS (Moodle coding style)
phpcs --standard=moodle local/aiconnect

# PHPUnit
cd /path/to/moodle
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter local_aiconnect
```

---

## Support

- Bugs and questions: please use the contact form at <https://goldnat.ai/contact> (please name the plugin and its version). This is our main channel for bug reports.
- GitHub issues: <https://github.com/chgold/moodle-ai-connect/issues> are also read, but the contact form gets a faster answer.
- Security issues: see `SECURITY.md` (please do not open a public issue)
- Documentation: <https://plugins.goldnat.ai/moodle>

## Repository

<https://github.com/chgold/moodle-ai-connect>
