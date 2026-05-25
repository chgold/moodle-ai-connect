# Moodle AI Connect — Agent Instructions

## Scope
Work exclusively within `/workspace/plugins/moodle/moodle-ai-connect/`.
Do NOT touch `/workspace/plugins-manager/` or any other platform directory.

Plugin type: `local` (`local_aiconnect`)

## Token Management (mandatory)
This plugin complies with **Token Management UI & Lifecycle v1.0** (`/workspace/.specs/TOKEN-MANAGEMENT-SPEC.md`).

Minimum requirements:
- `local_aiconnect_token_registry` table with prefix-only storage (16 chars)
- `last_used_at`, `last_used_ip`, `last_used_ua` — updated on every request via `touch_last_used()`
- User-facing page `/local/aiconnect/mytokens.php`
- 6 filters: Active / Renewable / Unused / Inactive / Revoked / All
- Cascade revoke to `local_aiconnect_tokens` (kills refresh_token too) — security mandatory
- Bulk revoke filter-aware
- Lazy cleanup via Moodle Configuration

## Key source files
| File | Purpose |
|---|---|
| `classes/service/token_registry.php` | All registry operations + cascade |
| `classes/middleware/auth_middleware.php` | Bearer auth — IP+UA + lazy cleanup |
| `mytokens.php` + `mytokens/` | User-facing pages |
| `classes/admin/token_list.php` | Admin UI with filter + bulk |
| `classes/task/cleanup_expired_tokens.php` | Scheduled cleanup task |
| `db/install.xml` + `db/upgrade.php` | Schema |
| `lib.php` | `extend_navigation_user` hook |

## Tests
```bash
sudo -u moodle-dev bash -c 'find /workspace/plugins/moodle/moodle-ai-connect/classes -name "*.php" -exec php -l {} \;'
```

## Git
All git commands prefixed with `GIT_MASTER=1`. Do NOT commit without explicit user approval.
