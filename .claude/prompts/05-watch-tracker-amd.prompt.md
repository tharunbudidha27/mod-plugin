# Prompt — Generate Watch Tracker AMD Module (Phase D)

```
You are @watch-tracker working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase D
- .claude/skills/05-watch-tracker-amd.md
- .claude/rules/security.md (S3, S6)
- .claude/rules/consumer-contract.md (CC2)

TASK: Generate:
- mod/fastpix/amd/src/watch_tracker.js
- The matching mod/fastpix/amd/build/watch_tracker.min.js (committed; produced by `grunt amd`)

REQUIREMENTS:
1. init() locates `[data-region="fastpix-player-wrapper"]` and reads sessionToken, activityId, cmId from data attributes.
2. Subscribes to <fastpix-player> events:
   - timeupdate: track watchedSeconds (max so far; ignore backward jumps).
   - seeked: increment seekCount.
3. Every 10 seconds, POSTs to mod_fastpix_record_view_progress via core/ajax (CC2):
   - Payload: { activity_id, watched_seconds, client_seek_count, session_token }.
4. Retry policy:
   - On 401/403: silent retry once, then stop posting (clear interval). Don't disrupt UX.
   - On 5xx: rely on core/ajax built-in retry (don't add a custom loop).
5. On `beforeunload`, clear the interval cleanly.

DO NOT:
- Use raw fetch() (CC2).
- Log session_token, playback_token, or userid (S6).
- Add infinite retry loops.
- Add WebSocket or alternative transport.
- Try to derive activity context from URL or globals — read it from the wrapping div data attributes (CC4).
- Decrement watchedSeconds on backward seek — server check #3 handles regression.

VALIDATION:
- AMD posts every 10s while video plays.
- Backward seek does not decrement watchedSeconds locally.
- 401/403 → silent retry once, then stop.
- 5xx → core/ajax retries; module doesn't add its own.
- beforeunload clears interval (no zombie timers).
- Behat: completion_grade.feature and no_skip_enforcement.feature exercise this end-to-end.
```
