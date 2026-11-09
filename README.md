# AI Connect for Moodle (`local_aiconnect`)

> WebMCP server that lets AI agents (Claude, ChatGPT, Gemini, Copilot, Grok,
> Perplexity, …) talk to your Moodle site over OAuth 2 + PKCE.

- **Component:** `local_aiconnect`
- **Type:** local plugin
- **Compatibility:** Moodle **4.3+** (`$plugin->requires = 2023100900`)
- **Maturity:** `MATURITY_STABLE`
- **Release:** `2.2.2`
- **License:** GNU GPL v3 or later

---

## Features

| Tier | Tool | Scope | Description |
|------|------|-------|-------------|
| Free | `moodle.getCourses` | `read` | List courses the caller is enrolled in |
| Free | `moodle.getCourse` | `read` | Single course details |
| Free | `moodle.getEnrollments` | `read` | Courses the user is enrolled in |
| Free | `moodle.getAssignments` | `read` | Assignments inside a course |
| Free | `moodle.getGrades` | `read` | Grades for the caller in a course |
| Free | `moodle.getUserProfile` | `read` | Profile of the calling user |
| Pro  | `moodle.enrollUser` | `write` | Enrol a user into a course |
| Pro  | `moodle.createAssignment` | `write` | Create a new assignment |
| Pro  | `moodle.submitGrade` | `write` | Save a grade for an assignment |
| Pro  | `moodle.sendMessage` | `write` | Send a message via Moodle messaging |
| Pro  | `moodle.updateCourse` | `write` | Update course fullname/summary |
| Pro  | `moodle.searchUsers` | `write` | Search users by name or email |

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
| `GET`  | `/local/aiconnect/manifest.php` | WebMCP manifest (public) |
| `GET`  | `/local/aiconnect/info.php` | Human-readable info & token generator |
| `GET`/`POST` | `/local/aiconnect/oauth.php?action=authorize` | OAuth 2 consent |
| `POST` | `/local/aiconnect/oauth.php?action=token` | Token grant |
| `POST` | `/local/aiconnect/oauth.php?action=revoke` | RFC 7009 token revocation — no auth; `token` (access or refresh, revokes the whole pair) + optional `token_type_hint`; always 200, 400 only when `token` is missing. Advertised in the manifest as `auth.revocation_url` |
| `POST` | `/local/aiconnect/oauth.php?action=generate_token` | Helper for logged-in admins |
| `POST` | `/local/aiconnect/v1/tools/{tool}` | Execute a tool |
| `POST` | `/local/aiconnect/tools.php` | Same, with `?tool=` query parameter |

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

# 5. Pro tool — enroll a user (requires write scope)
curl -s -X POST "http://localhost:8088/local/aiconnect/v1/tools/moodle.enrollUser" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"userid": 7, "courseid": 2, "roleid": 5}' | jq .
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
| Pro tools | Refused unless scope contains `write` |
| Permission ceiling (SEC-00) | Security: H5P / BigBlueButton tools return only what the user sees natively — hidden/restricted activities filtered (`cm_info::uservisible`), attempts own-only without `mod/h5pactivity:reviewattempts`, BBB attendance needs course report capability, join honours `:join`, groups and opening/closing time (2.2.2) |
| Privacy | `\local_aiconnect\privacy\provider` exports/deletes user data (GDPR) |
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

## Repository

<https://github.com/chgold/moodle-ai-connect>
