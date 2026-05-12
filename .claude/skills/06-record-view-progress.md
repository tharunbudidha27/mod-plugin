# Skill 06 — record_view_progress + Six Fraud Checks

**Owner agent:** `@watch-tracker`. **Pair with:** `@testing` for boundary tests.

**When to invoke:** Phase D, step 2. The most security-critical surface in the plugin.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase D and §10.3 (six checks verbatim from design doc).
- `.claude/rules/security.md` (S3, S4, S5).
- `.claude/rules/architecture.md` (A6 — fraud logic in service, not endpoint).

## Outputs

- `mod/fastpix/classes/external/record_view_progress.php`
- `mod/fastpix/classes/service/watch_tracker_service.php`
- Updated `db/services.php` registering the new external function

## Steps

### 1. External function — auth dance + delegate (no business logic)

```php
namespace mod_fastpix\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

class record_view_progress extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'activity_id'       => new external_value(PARAM_INT, 'mod_fastpix.id'),
            'watched_seconds'   => new external_value(PARAM_INT, 'cumulative seconds watched'),
            'client_seek_count' => new external_value(PARAM_INT, 'client-side seek counter'),
            'session_token'     => new external_value(PARAM_ALPHANUMEXT, 'HMAC session token'),
        ]);
    }

    public static function execute(int $activity_id, int $watched_seconds,
                                    int $client_seek_count, string $session_token): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'activity_id', 'watched_seconds', 'client_seek_count', 'session_token'
        ));

        $cm = get_coursemodule_from_instance('fastpix', $params['activity_id'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);    // includes require_login + sesskey
        require_capability('mod/fastpix:view', $context);

        // Delegate to the service. ALL fraud logic lives there (A6).
        $result = \mod_fastpix\service\watch_tracker_service::instance()->record_progress(
            $params['activity_id'],
            $GLOBALS['USER']->id,
            $params['watched_seconds'],
            $params['client_seek_count'],
            $params['session_token']
        );

        return [
            'accepted'          => $result->accepted,
            'fraud_reason'      => $result->fraud_reason,        // null on accept
            'completion_state'  => $result->completion_state,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'accepted'         => new external_value(PARAM_BOOL, ''),
            'fraud_reason'     => new external_value(PARAM_ALPHANUMEXT, '', VALUE_OPTIONAL),
            'completion_state' => new external_value(PARAM_ALPHANUMEXT, ''),
        ]);
    }
}
```

### 2. watch_tracker_service::record_progress — six checks IN ORDER

```php
namespace mod_fastpix\service;

class watch_tracker_service {

    private const TOLERANCE_SECONDS = 10;        // §10.4 abuse ceiling — DO NOT change without ADR

    public function record_progress(
        int $activity_id, int $userid, int $watched_seconds,
        int $client_seek_count, string $session_token
    ): record_progress_result {
        global $DB;

        // Load activity, attempt, asset.
        $activity = $DB->get_record('fastpix', ['id' => $activity_id], '*', MUST_EXIST);
        $attempt = $DB->get_record('fastpix_attempt', [
            'userid' => $userid, 'activity_id' => $activity_id,
        ], '*', MUST_EXIST);
        $asset = \local_fastpix\service\asset_service::instance()
            ->get_by_internal_id($activity->fastpix_asset_id);

        // Verify session token (S2 — hash_equals).
        if (!session_token_service::instance()->verify(
            $session_token, $userid, $activity_id, $attempt->session_start_ts
        )) {
            throw new \moodle_exception('invalidsessiontoken', 'mod_fastpix');
        }

        $now = time();
        $elapsed_session = $now - $attempt->session_start_ts;
        $elapsed_since_last = $attempt->last_callback_ts
            ? $now - $attempt->last_callback_ts : $elapsed_session;

        // Six checks IN ORDER. (S4) — Each one increments fraud_count if it fires.
        $fraud_reasons = [];

        // Check 1: exceeds_duration
        if ($watched_seconds > $asset->duration) {
            $fraud_reasons[] = 'exceeds_duration';
        }

        // Check 2: exceeds_wall_clock (10s tolerance)
        if ($watched_seconds > $elapsed_session + self::TOLERANCE_SECONDS) {
            $fraud_reasons[] = 'exceeds_wall_clock';
        }

        // Check 3: regression
        if ($watched_seconds < $attempt->watched_seconds) {
            $fraud_reasons[] = 'regression';
        }

        // Check 4: implausible_gain (10s tolerance)
        $gain = $watched_seconds - $attempt->watched_seconds;
        if ($gain > $elapsed_since_last + self::TOLERANCE_SECONDS) {
            $fraud_reasons[] = 'implausible_gain';
        }

        // Check 5: capability_lost
        $context = \context_module::instance(
            get_coursemodule_from_instance('fastpix', $activity_id)->id
        );
        if (!has_capability('mod/fastpix:view', $context, $userid)) {
            $fraud_reasons[] = 'capability_lost';
        }

        // Check 6: seek_on_noskip
        if ($asset->no_skip_required && $client_seek_count > $attempt->seek_count) {
            $fraud_reasons[] = 'seek_on_noskip';
        }

        // Apply outcomes.
        if (!empty($fraud_reasons)) {
            // Increment fraud_count by ONE per call (not by reason count). Record the first reason.
            $DB->set_field('fastpix_attempt', 'fraud_count',
                $attempt->fraud_count + 1, ['id' => $attempt->id]);
            $DB->set_field('fastpix_attempt', 'last_fraud_reason',
                $fraud_reasons[0], ['id' => $attempt->id]);
            return record_progress_result::rejected($fraud_reasons[0], $attempt->completion_state);
        }

        // Clean callback — update attempt.
        $update = (object)[
            'id' => $attempt->id,
            'watched_seconds' => $watched_seconds,
            'seek_count' => $client_seek_count,
            'last_callback_ts' => $now,
        ];
        $DB->update_record('fastpix_attempt', $update);

        // Fire milestone events (CG5 — idempotent).
        $this->fire_milestones_if_crossed($attempt, $watched_seconds, $asset->duration);

        // Recompute completion via Moodle's API (CG4).
        $completion_state = $this->recompute_completion($activity, $userid);

        return record_progress_result::accepted($completion_state);
    }
}
```

### 3. Milestones + completion + grade (calls into completion-grading service)

These delegate to `@completion-grading`'s code. Skill 08 + 09 cover them.

## Validation — boundary tests for all six checks

For each check, write at least:
- 1 test at threshold − 1 → ACCEPT, watched_seconds updated, fraud_count unchanged.
- 1 test at threshold + 1 → REJECT, watched_seconds NOT updated, fraud_count incremented, last_fraud_reason set correctly.

Tolerance boundary tests (checks 2 and 4):
- At `gain = elapsed + 10` → accept (≤ tolerance).
- At `gain = elapsed + 11` → reject.

Capability test (check 5):
- User has capability → accept.
- User loses capability mid-session → reject with `capability_lost`.

Coverage target: ≥ 90% on `watch_tracker_service` (M6 / §12.5).
