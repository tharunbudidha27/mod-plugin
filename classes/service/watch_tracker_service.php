<?php
namespace mod_fastpix\service;

use mod_fastpix\event\watch_milestone;

defined('MOODLE_INTERNAL') || die();

/**
 * Watch-progress recorder + the six fraud checks (rule S4).
 *
 * Hot path for every 10-second client callback. The contract is:
 *   record_progress(activity, attempt, client_intervals, current_position,
 *                   ended_fired, client_seek_count, context, now)
 *     → updated_attempt + coverage_percent + completion_state
 *
 * Fraud-check order is fixed (PR-9). ALL six run on every call — never
 * short-circuit. fraud_count is incremented once per failing check;
 * last_fraud_reason captures the FIRST failure (S4). On any failure,
 * watched_intervals / current_position / seek_count / last_callback_ts
 * are NOT updated and completion is NOT triggered.
 *
 * Direct DB writes to fastpix_attempt are allowed here; gradebook /
 * grade_grades writes are routed through Moodle's grade_update() in
 * a later step (CG1).
 *
 * Auth contract (rule S3 / PR-7): the caller — i.e. the external
 * function record_view_progress::execute — MUST already have invoked
 * session_token_service::verify (via resolve_active_attempt) before
 * handing the resolved $attempt row to record_progress(). This service
 * trusts that the attempt has been verified upstream; the in-service
 * fraud-check ⑤ (capability_lost) plus the resolve_active_attempt
 * chain on every endpoint hit are the two halves of the same contract.
 */
class watch_tracker_service {

    /** Epsilon (seconds) below which two intervals collapse on merge. */
    const MERGE_EPS_S = 0.01;

    /** Tolerance (seconds) for the wall-clock fraud checks (S4 ②, ④). */
    const WALL_CLOCK_TOLERANCE_S = 10;

    /** Defensive cap — never accept more than this many intervals from client. */
    const MAX_INTERVALS = 1000;

    /** Watch milestones (percentage) fired exactly once per (user, activity) pair. */
    const MILESTONES = [25, 50, 75, 100];

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Concat → sort by start → merge adjacent/overlapping (gap <= MERGE_EPS_S).
     * Output is sorted by start, non-overlapping. Pure; no IO.
     *
     * @param array<array{0:float|int,1:float|int}> $existing
     * @param array<array{0:float|int,1:float|int}> $new
     * @return array<array{0:float,1:float}>
     */
    public function merge_intervals(array $existing, array $new): array {
        $all = [];
        foreach (array_merge($existing, $new) as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1])) {
                $all[] = [(float)$iv[0], (float)$iv[1]];
            }
        }
        usort($all, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($all as $cur) {
            $n = count($merged);
            if ($n > 0 && $cur[0] - $merged[$n - 1][1] <= self::MERGE_EPS_S) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $cur[1]);
            } else {
                $merged[] = [$cur[0], $cur[1]];
            }
        }
        return $merged;
    }

    /**
     * Sum (end - start) over the interval set.
     *
     * @param array<array{0:float|int,1:float|int}> $intervals
     */
    public function coverage_seconds(array $intervals): float {
        $total = 0.0;
        foreach ($intervals as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1])) {
                $total += max(0.0, (float)$iv[1] - (float)$iv[0]);
            }
        }
        return $total;
    }

    /**
     * Run the six fraud checks in fixed order (S4), persist progress on
     * a clean callback, increment fraud_count + record reason on any
     * violation. Returns the post-checks attempt row plus derived counters.
     *
     * @param \stdClass $activity            mdl_fastpix row (needs no_skip_required, completion_watch_percent, course)
     * @param \stdClass $attempt             mdl_fastpix_attempt row (mutated in-place)
     * @param \stdClass $asset               local_fastpix asset_summary DTO (needs duration)
     * @param array<array{0:float,1:float}> $client_intervals  Decoded client payload (already capped)
     * @param float $current_position        Client-reported playback head, seconds
     * @param bool  $ended_fired             Client saw the 'ended' event
     * @param int   $client_seek_count       Monotonic seek counter from client
     * @param \context_module $context       Module context (for capability check)
     * @param int   $now                     Wall-clock time (for testability)
     * @return \stdClass {
     *     attempt: \stdClass,             // updated row
     *     coverage_percent: int,          // post-merge coverage (or pre-merge on fraud)
     *     completion_state: string,       // 'in_progress' | 'complete'
     *     fraud_reasons: string[],        // every failing check this call
     * }
     */
    public function record_progress(
        \stdClass $activity,
        \stdClass $attempt,
        \stdClass $asset,
        array $client_intervals,
        float $current_position,
        bool $ended_fired,
        int $client_seek_count,
        \context_module $context,
        int $now
    ): \stdClass {
        global $DB;

        $existing = $this->decode_intervals_or_empty((string)($attempt->watched_intervals ?? ''));
        $duration = max(0, (int)($asset->duration ?? 0));

        $server_persisted = $this->coverage_seconds($existing);
        $client_claimed   = $this->coverage_seconds($client_intervals);
        $client_max_end   = $this->max_end($client_intervals);

        // ── Fraud checks (S4) — fixed order, no short-circuit (PR-9). ──
        $reasons = [];

        // ① exceeds_duration
        if ($duration > 0 && ($client_max_end > $duration || $client_claimed > $duration)) {
            $reasons[] = 'exceeds_duration';
        }

        // ② exceeds_wall_clock
        $elapsed = $now - (int)$attempt->session_start_ts;
        if ($client_claimed > $elapsed + self::WALL_CLOCK_TOLERANCE_S) {
            $reasons[] = 'exceeds_wall_clock';
        }

        // ③ regression
        if ($client_claimed < $server_persisted) {
            $reasons[] = 'regression';
        }

        // ④ implausible_gain
        $prev_callback = !empty($attempt->last_callback_ts)
            ? (int)$attempt->last_callback_ts
            : (int)$attempt->session_start_ts;
        $gain = $client_claimed - $server_persisted;
        $wall = $now - $prev_callback;
        if ($gain > $wall + self::WALL_CLOCK_TOLERANCE_S) {
            $reasons[] = 'implausible_gain';
        }

        // ⑤ capability_lost
        if (!has_capability('mod/fastpix:view', $context, (int)$attempt->userid, false)) {
            $reasons[] = 'capability_lost';
        }

        // ⑥ seek_on_noskip
        if (!empty($activity->no_skip_required) && $client_seek_count > (int)$attempt->seek_count) {
            $reasons[] = 'seek_on_noskip';
        }

        if (!empty($reasons)) {
            // Increment fraud_count once per failing check. Record FIRST reason
            // only (S4) — caller can inspect $result->fraud_reasons for the
            // full set this call.
            $attempt->fraud_count = (int)$attempt->fraud_count + count($reasons);
            $attempt->last_fraud_reason = $reasons[0];
            $DB->update_record('fastpix_attempt', $attempt);

            // Coverage exposed to client = whatever the server already has;
            // the client's claimed coverage is rejected.
            $coverage_percent = $duration > 0
                ? (int) min(100, round(($server_persisted / $duration) * 100))
                : 0;

            return (object)[
                'attempt'          => $attempt,
                'coverage_percent' => $coverage_percent,
                'completion_state' => $attempt->has_completed ? 'complete' : 'in_progress',
                'fraud_reasons'    => $reasons,
            ];
        }

        // ── Clean callback. Merge, persist, fire milestones, recompute completion. ──
        $merged = $this->merge_intervals($existing, $client_intervals);
        $coverage_seconds_after = $this->coverage_seconds($merged);

        // tt.md edge case #14 — reload mid-lesson without saved state.
        // Defensive belt-and-braces: if merging somehow produced LESS coverage
        // than the server already had (impossible geometrically since merge
        // is union, but possible if a future helper bug regressed precision
        // or column-corruption sneaks bad data through), prefer the server's
        // existing set. This NEVER suppresses fraud check ③ — that branch
        // returns before reaching here. The 0.5s tolerance protects against
        // float-precision noise from json_decode → float conversion.
        if ($coverage_seconds_after < $this->coverage_seconds($existing) - 0.5) {
            $merged = $existing;
            $coverage_seconds_after = $this->coverage_seconds($merged);
        }

        $coverage_percent = $duration > 0
            ? (int) min(100, round(($coverage_seconds_after / $duration) * 100))
            : 0;

        $attempt->watched_intervals = json_encode($merged);
        $attempt->current_position  = max(0.0, $current_position);
        $attempt->seek_count        = $client_seek_count;
        $attempt->last_callback_ts  = $now;

        // CG4 — capture the pre-update state so the transition test is
        // strictly 0 → 1. Sticky completion (any subsequent callback after
        // has_completed=1 is a replay) must NOT re-fire the grade write.
        $was_complete = !empty($attempt->has_completed);

        $threshold = (int)($activity->completion_watch_percent ?? 90);
        // Completion gate: coverage must reach the threshold. The 'ended'
        // event alone is NOT a shortcut — a learner who seeks to the end
        // without watching has zero coverage and zero completion. The
        // ended_fired flag is still recorded for fraud audit, just not as
        // a completion trigger.
        $crossed_threshold = $coverage_percent >= $threshold;
        if ($crossed_threshold && !$was_complete) {
            $attempt->has_completed = 1;
        }

        $is_complete = !empty($attempt->has_completed);

        $DB->update_record('fastpix_attempt', $attempt);

        // CG4 — exactly-once grade write on the 0 → 1 transition. Goes through
        // grade_update() via lib.php's bare-name fastpix_grade_item_update
        // (CG1 / PR-6: never $DB->{insert,update}_record on grade_grades).
        if (!$was_complete && $is_complete) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/fastpix/lib.php');
            // grade_update REQUIRES the $grades array to be keyed by user id
            // (Moodle's gradelib emits "Incorrect grade array index" and
            // silently drops the entry otherwise — even with userid set on
            // the object, the array KEY is what matters).
            $userid = (int)$attempt->userid;
            $grades = [
                $userid => (object)[
                    'userid'     => $userid,
                    'rawgrade'   => (float)($activity->grademax ?? 100),
                    'dategraded' => $now,
                ],
            ];
            fastpix_grade_item_update($activity, $grades);
        }

        // Milestones (CG5) — fire once per (user, activity, milestone) pair,
        // and persist the timestamp in the same row so replays do not re-fire.
        $this->fire_milestones_for($attempt, $coverage_percent, $now);

        // Completion recomputation per CG4 — pass COMPLETION_UNKNOWN, not
        // COMPLETION_COMPLETE, so Moodle invokes our custom_completion rule
        // (Step 5). Tests with completion disabled skip this branch.
        try {
            $course = $DB->get_record('course', ['id' => (int)$activity->course], '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('fastpix', (int)$activity->id, 0, false, MUST_EXIST);
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
            }
        } catch (\Throwable $e) {
            // Completion is best-effort here — never block progress recording
            // on a completion-side failure. Logged for audit.
            debugging('mod_fastpix: completion update_state failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return (object)[
            'attempt'          => $attempt,
            'coverage_percent' => $coverage_percent,
            'completion_state' => !empty($attempt->has_completed) ? 'complete' : 'in_progress',
            'fraud_reasons'    => [],
        ];
    }

    /**
     * Fire any milestone events not yet fired for this attempt. Idempotent
     * by virtue of the milestone_*_at column being non-null sentinel.
     * The transaction makes the (set column, fire event) pair atomic so a
     * concurrent callback cannot double-fire.
     */
    private function fire_milestones_for(\stdClass $attempt, int $coverage_percent, int $now): void {
        global $DB;

        foreach (self::MILESTONES as $milestone) {
            if ($coverage_percent < $milestone) {
                continue;
            }
            $column = 'milestone_' . $milestone . '_at';
            if (!empty($attempt->{$column})) {
                continue;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                // Re-read inside the transaction in case a concurrent
                // record_progress call fired this milestone first.
                $current = (int) $DB->get_field(
                    'fastpix_attempt',
                    $column,
                    ['id' => $attempt->id]
                );
                if ($current === 0) {
                    $DB->set_field('fastpix_attempt', $column, $now, ['id' => $attempt->id]);
                    $attempt->{$column} = $now;
                    watch_milestone::create_from_attempt((int)$attempt->id, $milestone)->trigger();
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
                throw $e;
            }
        }
    }

    /**
     * Tolerant JSON decoder for the stored intervals column. Returns []
     * on any error rather than throwing — the column is a server-owned
     * encoding and corrupt content here is an invariant break, not a
     * user-facing condition.
     */
    private function decode_intervals_or_empty(string $json): array {
        if ($json === '' || $json === '[]') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $clean = [];
        foreach ($decoded as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1])) {
                $clean[] = [(float)$iv[0], (float)$iv[1]];
            }
        }
        return $clean;
    }

    /**
     * Largest interval end seen — used by fraud check ①.
     */
    private function max_end(array $intervals): float {
        $max = 0.0;
        foreach ($intervals as $iv) {
            if (is_array($iv) && isset($iv[1])) {
                $max = max($max, (float)$iv[1]);
            }
        }
        return $max;
    }
}
