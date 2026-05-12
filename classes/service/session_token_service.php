<?php
namespace mod_fastpix\service;

defined('MOODLE_INTERNAL') || die();

/**
 * HMAC-bound session token issuer + verifier (rule S1).
 *
 * Token = hash_hmac('sha256', "userid|activity_id|session_start_ts", session_secret).
 * TTL = 4h, enforced by session_start_ts. Comparison is constant-time (S2).
 * The secret is bootstrapped on install (db/install.php) and is never logged (S6).
 */
class session_token_service {

    /** Session lifetime in seconds (4h). */
    const TTL_SECONDS = 14400;

    /** @var self|null */
    private static $instance = null;

    /** @var string|null Lazily resolved HMAC secret. */
    private $secret = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Issue a fresh session token.
     */
    public function issue(int $userid, int $activity_id, int $session_start_ts): string {
        $message = $userid . '|' . $activity_id . '|' . $session_start_ts;
        return hash_hmac('sha256', $message, $this->get_secret(), false);
    }

    /**
     * Verify a provided token against the stored row token. Constant-time.
     * Also checks the 4h TTL.
     */
    public function verify(string $provided, string $expected, int $session_start_ts): bool {
        if ($session_start_ts <= 0) {
            return false;
        }
        if ((time() - $session_start_ts) > self::TTL_SECONDS) {
            return false;
        }
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    /**
     * True if the session has not exceeded TTL. Used by callers that need to
     * decide whether to reuse an existing attempt row.
     */
    public function is_within_ttl(int $session_start_ts): bool {
        return $session_start_ts > 0 && (time() - $session_start_ts) <= self::TTL_SECONDS;
    }

    /**
     * Resolve the active in-progress attempt for (userid, activity_id) and
     * verify the supplied token in constant time. Centralises the three
     * failure modes the refresh endpoint used to inline:
     *
     *   - no attempt row exists                 → error_session_no_attempt
     *   - attempt finalised (≠ in_progress)      → error_session_finalised
     *   - token mismatch / expired               → error_session_invalid
     *
     * @throws \moodle_exception with one of the three lang keys above.
     */
    public function resolve_active_attempt(int $userid, int $activity_id, string $provided_token): \stdClass {
        global $DB;

        $attempt = $DB->get_record(
            'fastpix_attempt',
            ['userid' => $userid, 'activity_id' => $activity_id]
        );
        if (!$attempt) {
            throw new \moodle_exception('error_session_no_attempt', 'mod_fastpix');
        }
        if ($attempt->completion_state !== 'in_progress') {
            throw new \moodle_exception('error_session_finalised', 'mod_fastpix');
        }
        if (!$this->verify(
            $provided_token,
            (string)$attempt->session_token,
            (int)$attempt->session_start_ts
        )) {
            throw new \moodle_exception('error_session_invalid', 'mod_fastpix');
        }
        return $attempt;
    }

    private function get_secret(): string {
        if ($this->secret === null) {
            $secret = get_config('mod_fastpix', 'session_secret');
            if (empty($secret)) {
                // Auto-heal if for any reason install.php did not run (e.g. older
                // installs predating Phase C). Never echo or log this value.
                $secret = bin2hex(random_bytes(32));
                set_config('session_secret', $secret, 'mod_fastpix');
            }
            $this->secret = $secret;
        }
        return $this->secret;
    }
}
