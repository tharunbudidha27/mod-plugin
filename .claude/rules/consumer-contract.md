# Consumer Contract Rules (CC1–CC8)

These rules govern how `mod_fastpix` consumes `local_fastpix`. Cited by `@local-fastpix-contract`, `@activity-form`, `@playback-view`, `@watch-tracker`, and `@pr-reviewer`.

The principle: **`mod_fastpix` is a strict consumer of a frozen public API.** Treat `local_fastpix`'s service layer as you would a SaaS vendor — you don't poke at internals, you don't bypass the documented interface, and if you need something new, you file a request.

---

## CC1 — The allowed consumed surface is exactly four services

**Rule.** `mod_fastpix` may import these classes only:

| Class | Purpose | Used in mod_fastpix by |
|---|---|---|
| `\local_fastpix\service\asset_service` | Read asset metadata | `playback_service`, `view.php`, `mod_form.php` |
| `\local_fastpix\service\playback_service` | Mint DRM tokens for playback | `playback_service` (mod_fastpix's own wrapper) |
| `\local_fastpix\service\upload_service` | Create upload sessions, URL pull | `mod_form.php` (via web service call) |
| `\local_fastpix\service\feature_flag_service` | Read DRM-enabled, no-skip-allowed, etc. | `mod_form.php`, `playback_service` |

These four are the entire consumed surface. Any other namespace is forbidden (per A4).

**Enforcement.** CI script `.claude/ci-checks/grep-no-direct-gateway.sh`.

**Failure routing.** `@local-fastpix-contract`.

---

## CC2 — Web service calls go through Moodle's web service infrastructure

**Rule.** From AMD modules in `mod_fastpix`, calls to `local_fastpix_*` web services use:

```javascript
import {call as fetchMany} from 'core/ajax';

const response = await fetchMany([{
    methodname: 'local_fastpix_create_upload_session',
    args: { activity_context: cmid, ... }
}])[0];
```

Do NOT construct raw `fetch()` calls to `/lib/ajax/service.php`. Do NOT bypass sesskey. The `core/ajax` module handles sesskey, error normalization, and retry semantics.

**Enforcement.** PR review.

**Failure routing.** `@activity-form` (uploads) or `@watch-tracker` (playback).

---

## CC3 — `mod_fastpix` does NOT consume `local_fastpix` admin web services

**Rule.** The web services `local_fastpix_test_connection` and `local_fastpix_send_test_event` are admin-only (require `local/fastpix:configurecredentials`). `mod_fastpix` MUST NOT call them. They are part of the admin settings UX, not the activity surface.

**Enforcement.** PR review; CI grep for these method names returns zero matches in `mod_fastpix/`.

**Failure routing.** `@local-fastpix-contract`.

---

## CC4 — `mod_fastpix` exposes its own web services for its own concerns

**Rule.** `mod_fastpix/db/services.php` declares:
- `mod_fastpix_record_view_progress` — called by `amd/src/watch_tracker.js` every 10s
- `mod_fastpix_refresh_playback_token` — called by `amd/src/player.js` ~30s before JWT expiry

Capability: `mod/fastpix:view` for both. Type: `write` for `record_view_progress` (modifies attempt row), `read` for `refresh_playback_token` (mints a fresh token but doesn't modify state on our side; the gateway side is logged in `local_fastpix`).

These web services live in `mod_fastpix\external\*`, not in `local_fastpix`. The reason: both need activity context (capability check, attempt row, session token). Putting them in `local_fastpix` would force `local_fastpix` to know about activities, violating the dependency direction (A4).

**Enforcement.** PR review.

**Failure routing.** `@watch-tracker` or `@playback-view`.

---

## CC5 — Read-only access to `mdl_local_fastpix_*` tables, only via service

**Rule.** `mod_fastpix` reads `mdl_local_fastpix_asset` and `mdl_local_fastpix_track` via the `asset_service` API. Direct `$DB->get_record('local_fastpix_asset', ...)` is forbidden — it bypasses the cache layer in `asset_service` and creates a hot-path performance risk.

```php
// CORRECT
$asset = \local_fastpix\service\asset_service::instance()->get_by_fastpix_id($fastpix_id);

// FORBIDDEN
$asset = $DB->get_record('local_fastpix_asset', ['fastpix_id' => $fastpix_id]);
```

**Enforcement.** CI grep for `local_fastpix_asset` and `local_fastpix_track` outside of `*_test.php` files returns zero matches.

**Failure routing.** `@local-fastpix-contract`.

---

## CC6 — Token refresh respects the documented TTL contract

**Rule.** Per `01-local-fastpix.md` JWT spec, playback JWTs have TTL 300s. The `mod_fastpix\external\refresh_playback_token` web service:
- Re-validates `mod/fastpix:view`
- Re-validates the session token (must still match `mdl_fastpix_attempt.session_token`)
- Re-validates that the attempt is still in `in_progress` state
- Calls `\local_fastpix\service\playback_service::resolve()` for a fresh JWT
- Returns `{ playback_token, expires_at_ts }`

The AMD module schedules the next refresh at `expires_at_ts - 30s`. If the user closes the tab, no further refresh happens; the in-flight JWT expires harmlessly.

**Enforcement.** `tests/refresh_playback_token_test.php` covers: capability lost mid-session → 403; session token mismatch → 401; attempt completed → 410 (gone, no further refreshes).

**Failure routing.** `@playback-view`.

---

## CC7 — `local_fastpix` interface drift requires an ADR before consuming

**Rule.** If `local_fastpix` adds, changes, or deprecates a method on `asset_service`, `playback_service`, `upload_service`, or `feature_flag_service`:

1. `@local-fastpix-contract` reviews the change.
2. If `mod_fastpix` needs to consume the new method, an ADR is required.
3. Until the ADR lands, the existing interface is the contract.

This protects `mod_fastpix` from accidental coupling to in-flight changes upstream.

**Enforcement.** PR review.

**Failure routing.** `@local-fastpix-contract`.

---

## CC8 — Never reach into `local_fastpix` DTOs

**Rule.** `local_fastpix` services return DTO objects. `mod_fastpix` consumes the documented public properties only:

| DTO | Public properties (consume) | Internal (do NOT touch) |
|---|---|---|
| `playback_payload` (returned by `playback_service::resolve`) | `playback_id`, `playback_token`, `expires_at_ts`, `drm_required`, `accent_color`, `default_show_captions` | Anything else, including methods. |
| `asset_summary` (returned by `asset_service::get_by_fastpix_id`) | `fastpix_id`, `playback_id`, `status`, `duration`, `drm_required`, `no_skip_required`, `has_captions`, `deleted_at` | `owner_userid`, `last_event_at`, internal status fields. |
| `upload_session` (returned by `upload_service::create_*`) | `upload_session_id`, `upload_url`, `expires_at_ts` | Anything else. |

If you need a property that's not on this list, the answer is NOT to add it on the consumer side. Route through `@local-fastpix-contract` for an interface change.

**Enforcement.** PR review.

**Failure routing.** `@local-fastpix-contract`.

---

## CC9 — Custom-element attributes match the upstream component's documented surface

**Rule.** When a mustache template emits a custom HTML element (any tag with a hyphen, e.g. `<fastpix-player>`), the attribute names MUST match those documented by the web component's author. Do NOT invent attribute names that "feel right" based on the local DTO field name (`playback-token` ≠ `token`; `caption-default` ≠ `default-subtitle-track`). Read the upstream README/docs once, lock the mapping in the template, and document the source in a mustache comment.

The failure mode is silent: an unknown attribute is ignored by the custom element, no console error fires, no exception bubbles, and the only visible symptom is "the player is a black box that doesn't play." This burnt a full Phase C smoke cycle once — don't repeat it.

For `<fastpix-player>` specifically (pinned in `playback_service::PLAYER_LIB_URL`):

| `playback_payload` field | `<fastpix-player>` attribute |
|---|---|
| `playback_id` | `playback-id` |
| `playback_token` | `token` (and `drm-token` when `drm_required=true`; same JWT serves both per local_fastpix §3.5) |
| `accent_color` | `accent-color` |
| `default_show_captions` | NO direct mapping — player only takes `default-subtitle-track="<label>"`; let auto-detect handle it until track metadata is exposed |

If the upstream component renames an attribute in a future version, the pin in `PLAYER_LIB_URL` (a fixed semver tag) prevents silent breakage; the upgrade is an explicit code change in `view.mustache` plus a bump of the URL constant. Treat the upstream README as a versioned consumed surface — a major-version bump there is on par with a `local_fastpix` interface change (CC7) and triggers an ADR.

**Enforcement.** PR review of `view.mustache` and any future template that emits a hyphenated tag.

**Failure routing.** `@playback-view`.
