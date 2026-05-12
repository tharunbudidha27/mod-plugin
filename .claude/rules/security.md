# Security Rules (S1–S10)

These rules govern auth, fraud detection, secrets, and threat mitigation. Cited by `@watch-tracker`, `@playback-view`, `@privacy-security`, and `@pr-reviewer`.

---

## S1 — Session token is HMAC-bound

**Rule.** The session token issued on `view.php` is:

```php
$session_token = hash_hmac(
    'sha256',
    $userid . '|' . $activity_id . '|' . $session_start_ts,
    $session_secret,    // from get_config('mod_fastpix', 'session_secret')
    false               // hex output
);
```

TTL is 4 hours, enforced by `session_start_ts`. The token is stored in `mdl_fastpix_attempt.session_token` so the server can validate against the row directly (per D1).

The `session_secret` is auto-bootstrapped on plugin install via `mod_fastpix_install_callback` and stored in `mdl_config_plugins` with `passwordunmask` semantics (no plaintext echo).

**Enforcement.** `tests/session_token_service_test.php` asserts: same inputs → same token; different user → different token; different activity → different token; expired session_start_ts → reject.

**Failure routing.** `@watch-tracker`.

---

## S2 — All session token comparisons use `hash_equals`

**Rule.** Never `===` or `==` on session tokens. Always `hash_equals($expected, $provided)`. Constant-time comparison only.

**Enforcement.** CI grep for `==.*session_token` and `===.*session_token` returns zero matches outside test fixtures. PR review.

**Failure routing.** `@privacy-security` or `@watch-tracker`.

---

## S3 — Every web service requires sesskey + capability + session token

**Rule.** Every external function in `mod_fastpix\external\*` runs, in order:

```php
self::validate_parameters(self::execute_parameters(), $params);
$context = \context_module::instance($cmid);
self::validate_context($context);     // includes require_login + sesskey
require_capability('mod/fastpix:view', $context);
$service->verify_session_token($activity_id, $userid, $provided_token);
// only now does business logic run
```

The webhook exception that applies to `local_fastpix/webhook.php` does NOT apply here. Every `mod_fastpix` endpoint is authenticated.

**Enforcement.** PR review; `tests/external/*_test.php` covers the failure path for each missing check.

**Failure routing.** `@watch-tracker` or `@playback-view`.

---

## S4 — `record_view_progress` runs ALL six fraud checks in order

**Rule.** Per design doc §10.3, the order is:

1. `exceeds_duration` — `watched_seconds > asset.duration` → fraud
2. `exceeds_wall_clock` — `watched_seconds > (now - session_start_ts) + 10` → fraud
3. `regression` — `watched_seconds < attempt.watched_seconds` → fraud
4. `implausible_gain` — `(watched_seconds - prev) > (now - last_callback) + 10` → fraud
5. `capability_lost` — `has_capability('mod/fastpix:view', ...)` is false → fraud
6. `seek_on_noskip` — on `asset.no_skip_required=1`, `client_seek_count > attempt.seek_count` → fraud

The 10-second tolerance in checks 2 and 4 is the abuse ceiling per §10.4. Do NOT loosen it without an ADR. Do NOT reorder the checks. Do NOT short-circuit (every check increments fraud_count if it triggers; we record all violations, not just the first).

On any fraud, `mdl_fastpix_attempt.fraud_count` is incremented and `mdl_fastpix_attempt.last_fraud_reason` is set, but `watched_seconds` is NOT updated.

**Enforcement.** `tests/record_view_progress_test.php` has one boundary test per check; `@pr-reviewer` walks them on every PR that touches `watch_tracker_service`.

**Failure routing.** `@watch-tracker`.

---

## S5 — `fraud_count > 20` is a soft-block signal, not a hard kill

**Rule.** When `fraud_count > 20`, the gradebook view shows a row badge (gated by `mod/fastpix:viewallattempts`) for human review. The plugin does NOT auto-revoke completion, does NOT auto-block the user, does NOT silently stop accepting callbacks. Teacher escalation via `mod/fastpix:graderoverride` is the correction path.

**Enforcement.** `tests/record_view_progress_test.php` asserts callbacks continue to be accepted at `fraud_count = 21, 22, ...`.

**Failure routing.** `@watch-tracker` and `@completion-grading`.

---

## S6 — Never log raw user IDs, raw playback tokens, raw session tokens, or session_secret

**Rule.** Structured logs in `mod_fastpix` may include:
- `activity_id`, `course_id`, `cm_id` — public IDs
- `attempt_id` — internal, OK
- `fraud_reason` — the typed string (`exceeds_duration`, etc.)
- `watched_seconds`, `seek_count` — non-PII metrics

They MUST NOT include:
- Raw `userid` — use a hash if you absolutely need correlation
- Raw `session_token` — this is auth material
- Raw `playback-token` (DRM JWT) — this is auth material  
- `session_secret` — should never appear in any code path that touches log output

**Enforcement.** Log redaction canary in `tests/`; CI grep for `error_log\\(.*userid` and similar patterns.

**Failure routing.** `@privacy-security`.

---

## S7 — DRM L3 screen recording is an accepted v1.0 limitation

**Rule.** Per design doc §11.3 T2, screen recording on Widevine L3 (most desktop browsers) is unmitigated in v1.0. README.md documents this as a known limitation. Year-2 escalation is server-side burn-in (out of scope here).

Do NOT introduce client-side anti-record measures (key-event blocking, canvas obfuscation, etc.). They are easily bypassed and add UX friction without security benefit.

**Enforcement.** PR review.

**Failure routing.** `@backend-architect` (the answer is "no, that's v2.0").

---

## S8 — Capability checks at every privilege boundary

**Rule.** Per Moodle convention, every privileged action has a capability check before the action is allowed:

| Action | Capability |
|---|---|
| Add a `mod_fastpix` activity | `mod/fastpix:addinstance` (in `mod_form.php`'s context) |
| View a video | `mod/fastpix:view` (in `view.php`, `record_view_progress`, `refresh_playback_token`) |
| See all attempts (gradebook) | `mod/fastpix:viewallattempts` |
| Override a grade | `mod/fastpix:graderoverride` |

Forgetting any of these is a security bug, not a UX issue.

**Enforcement.** PR review; tests cover the negative case (insufficient capability → exception).

**Failure routing.** `@privacy-security`.

---

## S9 — Lang strings for capability descriptions must NOT leak system internals

**Rule.** Capability description strings in `lang/en/mod_fastpix.php` describe what the user can do, not how. "View a video and have completion tracked" is good. "Submit watch_tracker callbacks to record_view_progress endpoint" is bad — leaks internals to admins reading the role-permission UI.

**Enforcement.** PR review.

**Failure routing.** `@privacy-security`.

---

## S10 — Privacy provider declares every PII column in `mdl_fastpix_attempt`

**Rule.** `\mod_fastpix\privacy\provider::get_metadata()` declares:

```php
$collection->add_database_table(
    'fastpix_attempt',
    [
        'userid'          => 'privacy:metadata:fastpix_attempt:userid',
        'activity_id'     => 'privacy:metadata:fastpix_attempt:activity_id',
        'watched_seconds' => 'privacy:metadata:fastpix_attempt:watched_seconds',
        'seek_count'      => 'privacy:metadata:fastpix_attempt:seek_count',
        'fraud_count'     => 'privacy:metadata:fastpix_attempt:fraud_count',
        'session_token'   => 'privacy:metadata:fastpix_attempt:session_token',
        'completion_state' => 'privacy:metadata:fastpix_attempt:completion_state',
        'session_start_ts' => 'privacy:metadata:fastpix_attempt:session_start_ts',
        'last_callback_ts' => 'privacy:metadata:fastpix_attempt:last_callback_ts',
    ],
    'privacy:metadata:fastpix_attempt'
);
```

`delete_data_for_user`, `export_user_data`, and `get_users_in_context` are all required and must round-trip the data correctly.

**Enforcement.** `tests/privacy_provider_test.php` covers all three methods.

**Failure routing.** `@privacy-security`.
