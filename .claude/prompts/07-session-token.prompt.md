# Prompt — Generate Session Token Service (Phase C)

```
You are @watch-tracker working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase C and §10.2 (token contract)
- .claude/skills/07-session-token.md
- .claude/rules/security.md (S1, S2)

TASK: Generate:
- mod/fastpix/classes/service/session_token_service.php
- mod/fastpix/db/install.php (xmldb_mod_fastpix_install callback that auto-bootstraps session_secret)

REQUIREMENTS:
1. Auto-bootstrap session_secret on install:
   - If get_config('mod_fastpix', 'session_secret') is empty, generate via bin2hex(random_bytes(32)) (64 hex chars).
   - Store via set_config('session_secret', $secret, 'mod_fastpix').
2. session_token_service is a singleton (instance() returns the singleton).
3. issue($userid, $activity_id, $session_start_ts) returns hash_hmac('sha256', "$userid|$activity_id|$session_start_ts", $secret, false).
4. verify($provided, $userid, $activity_id, $session_start_ts) returns:
   - false if (now - session_start_ts) > 4 * HOURSECS (S1).
   - hash_equals(expected, provided) otherwise (S2 — never === or ==).
5. get_secret() throws coding_exception if secret missing or shorter than 32 chars.

DO NOT:
- Use === or == on tokens (S2 / PR-8).
- Log the secret (S6 / PR-21).
- Store the token outside mdl_fastpix_attempt.
- Reduce TTL below 4 hours without an ADR.

VALIDATION:
- Same inputs → same token (deterministic).
- Different user/activity/start → different tokens.
- TTL boundary: TTL − 1s accepts; TTL + 1s rejects.
- session_token_service coverage ≥ 90% (M6).
- CI grep for == on session_token returns zero matches outside test fixtures.
```
