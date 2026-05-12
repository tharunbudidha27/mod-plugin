# Prompt — Generate view.php + Processing UX (Phase C)

```
You are @playback-view working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase C
- docs/01-local-fastpix.md (playback_service::resolve signature; processing-state UX §13.2)
- .claude/skills/04-view-and-processing.md
- .claude/rules/security.md (S3, S6)
- .claude/rules/consumer-contract.md (CC1, CC4, CC6, CC8)

TASK: Generate:
- mod/fastpix/view.php (full)
- mod/fastpix/templates/view.mustache, processing.mustache, error.mustache
- mod/fastpix/classes/output/view_renderer.php
- mod/fastpix/classes/service/playback_service.php (mod_fastpix's wrapper)
- mod/fastpix/classes/external/refresh_playback_token.php
- mod/fastpix/amd/src/processing_state_poller.js
- mod/fastpix/classes/event/activity_viewed.php
- DTOs: view_state_player, view_state_processing, view_state_error in mod/fastpix/classes/dto/

REQUIREMENTS:
1. view.php auth dance: require_login → require_capability('mod/fastpix:view') → context_module → resolve playback → trigger activity_viewed event → set_module_viewed → render via renderer.
2. playback_service::resolve_for_view(activity, userid):
   - Look up asset via \local_fastpix\service\asset_service::get_by_internal_id (CC1, CC5).
   - If asset null/deleted → return view_state_error('videounavailable').
   - If asset.status !== 'ready' → return view_state_processing.
   - Else: get_or_create_attempt, mint playback token via \local_fastpix\service\playback_service::resolve, return view_state_player.
3. get_or_create_attempt: looks up existing row by (userid, activity_id). If session_start_ts within 4h, reuse. Else mint new session_token via session_token_service and update or insert.
4. view.mustache wraps `<fastpix-player>` in a `<div data-region="fastpix-player-wrapper" data-session-token="..." data-activity-id="..." data-asset-id="..." data-cm-id="...">` (CC4 — watch_tracker AMD reads context from these attributes).
5. processing.mustache shows the §13.2 message + ARIA-labeled progress bar; loads processing_state_poller.js.
6. error.mustache renders one of three reasons by lang key: videounavailable, drm_unsupported, capability_lost (no system internals — S9).
7. processing_state_poller.js polls local_fastpix_get_upload_status every 30s, max 10 polls, then "Refresh manually" button.
8. refresh_playback_token external function (CC6):
   - sesskey + capability + session token + attempt-state checks.
   - Calls \local_fastpix\service\playback_service::resolve for fresh JWT.
   - Returns { playback_token, expires_at_ts }.
9. activity_viewed event extends \core\event\base.

DO NOT:
- Call \local_fastpix\api\gateway directly (PR-3).
- Use WebSocket or SSE for processing-state polling (intentionally simple per §13.2).
- Log raw playback_token, raw session_token, or raw userid (S6).
- Show "Asset abc123 not found in workspace ws_..." — say "Video unavailable" (S9, ADR-010).
- Try to mitigate L3 screen recording (accepted v1.0 limitation, S7).

VALIDATION:
- Student opens activity → player renders.
- Asset processing → polling UI; transitions when ready.
- Asset deleted → "Video unavailable" gracefully.
- ARIA labels + keyboard nav verified manually.
- tests/playback_service_test.php ≥ 85% coverage.
- Behat: student_view.feature passes.
```
