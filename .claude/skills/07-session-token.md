# Skill 07 — Session Token Service (HMAC issue + verify)

**Owner agent:** `@watch-tracker`. **Pair with:** `@privacy-security` for secret bootstrap.

**When to invoke:** Phase C (issuance for view.php) and Phase D (verification for record_view_progress).

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase C (D1 decision: store token in attempt row).
- `.claude/rules/security.md` (S1, S2).

## Outputs

- `mod/fastpix/classes/service/session_token_service.php`
- `mod/fastpix/db/install.php` — `xmldb_mod_fastpix_install()` callback that auto-bootstraps `session_secret`

## Steps

### 1. Auto-bootstrap secret on install

```php
// mod/fastpix/db/install.php
defined('MOODLE_INTERNAL') || die();

function xmldb_mod_fastpix_install() {
    if (!get_config('mod_fastpix', 'session_secret')) {
        $secret = bin2hex(random_bytes(32));     // 64 hex chars
        set_config('session_secret', $secret, 'mod_fastpix');
    }
}
```

### 2. session_token_service

```php
namespace mod_fastpix\service;

class session_token_service {

    private const TTL_SECONDS = 4 * HOURSECS;        // 4 hours per S1

    private static ?self $instance = null;
    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function issue(int $userid, int $activity_id, int $session_start_ts): string {
        $secret = $this->get_secret();
        $payload = $userid . '|' . $activity_id . '|' . $session_start_ts;
        return hash_hmac('sha256', $payload, $secret, false);    // hex output
    }

    public function verify(string $provided, int $userid, int $activity_id, int $session_start_ts): bool {
        // Expiry check first (cheap).
        if (time() - $session_start_ts > self::TTL_SECONDS) {
            return false;
        }
        $expected = $this->issue($userid, $activity_id, $session_start_ts);
        return hash_equals($expected, $provided);    // S2 — constant-time
    }

    private function get_secret(): string {
        $secret = get_config('mod_fastpix', 'session_secret');
        if (empty($secret) || strlen($secret) < 32) {
            throw new \coding_exception('session_secret missing or too short');
        }
        return $secret;
    }
}
```

### 3. Tests

`tests/session_token_service_test.php` covers:

| Scenario | Expected |
|---|---|
| Same user/activity/start → same token | true |
| Different user → different token | true |
| Different activity → different token | true |
| Different start_ts → different token | true |
| Verify with correct token within TTL | true |
| Verify with correct token AFTER TTL | false |
| Verify with mutated token | false |
| Verify with token from different user | false |
| `===` comparison | NEVER used (CI grep) |

Coverage target: ≥ 90%.

## Validation

- Token is 64-character hex (SHA-256 hex output).
- TTL boundary: TTL − 1 second accepts; TTL + 1 second rejects.
- Constant-time comparison verified by CI grep.
- Secret never logged (S6 — `tests/log_redaction_test.php` covers this).
