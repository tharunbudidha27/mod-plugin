# Skill 12 — PHPUnit Test Scaffolding

**Owner agent:** `@testing`.

**When to invoke:** Every phase. Tests land in the same PR as the code they cover (M6).

---

## Inputs

- `docs/02-mod-fastpix.md` §5.
- `.claude/rules/moodle-mod.md` (M6 — coverage gates).
- `.claude/rules/security.md` (S4 — boundary tests for fraud checks).
- Moodle's `advanced_testcase` documentation.

## Outputs

- `mod/fastpix/tests/lib_test.php`
- `mod/fastpix/tests/mod_form_test.php`
- `mod/fastpix/tests/playback_service_test.php`
- `mod/fastpix/tests/session_token_service_test.php`
- `mod/fastpix/tests/record_view_progress_test.php`  (boundary tests for all 6 fraud checks)
- `mod/fastpix/tests/custom_completion_test.php`
- `mod/fastpix/tests/grade_completion_integration_test.php`
- `mod/fastpix/tests/backup_restore_test.php`
- `mod/fastpix/tests/privacy_provider_test.php`
- `mod/fastpix/tests/log_redaction_test.php`

## Steps

### 1. Skeleton — every test file

```php
namespace mod_fastpix;

defined('MOODLE_INTERNAL') || die();

class watch_tracker_service_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    // tests...
}
```

### 2. Mock pattern for `local_fastpix` services (CC1 — never mock the gateway)

```php
private function mock_asset_service(stdClass $asset_dto): void {
    $mock = $this->createMock(\local_fastpix\service\asset_service::class);
    $mock->method('get_by_internal_id')->willReturn($asset_dto);
    \local_fastpix\service\asset_service::set_test_instance($mock);
}
```

If `local_fastpix` doesn't expose a `set_test_instance`, route to `@local-fastpix-contract` for an interface change.

### 3. Boundary tests for the 6 fraud checks (S4)

For each check, two tests minimum:

```php
// Check 2: exceeds_wall_clock at tolerance boundary
public function test_check2_at_tolerance_accepts(): void {
    [$activity, $attempt, $asset] = $this->mock_session(duration: 600, session_start: time() - 100);
    $service = watch_tracker_service::instance();

    $result = $service->record_progress($activity->id, $attempt->userid, 110, 0, $attempt->session_token);

    $this->assertTrue($result->accepted);
    $this->assertNull($result->fraud_reason);
    $this->assertEquals(110, $this->reload_attempt($attempt->id)->watched_seconds);
}

public function test_check2_above_tolerance_rejects(): void {
    [$activity, $attempt, $asset] = $this->mock_session(duration: 600, session_start: time() - 100);
    $service = watch_tracker_service::instance();

    $result = $service->record_progress($activity->id, $attempt->userid, 111, 0, $attempt->session_token);

    $this->assertFalse($result->accepted);
    $this->assertEquals('exceeds_wall_clock', $result->fraud_reason);
    $this->assertEquals(0, $this->reload_attempt($attempt->id)->watched_seconds);    // unchanged
    $this->assertEquals(1, $this->reload_attempt($attempt->id)->fraud_count);
}
```

Repeat for checks 1, 3, 4, 5, 6.

### 4. Time-based tests must use a frozen clock

```php
public function setUp(): void {
    parent::setUp();
    $this->resetAfterTest(true);
    $this->clock = $this->mock_clock_with_frozen(1700000000);
}
```

This prevents flake on second-boundary crossings.

### 5. Log redaction canary (S6)

```php
public function test_no_session_token_in_logs(): void {
    $this->resetDebugging();

    [$activity, $attempt] = $this->mock_session();
    watch_tracker_service::instance()->record_progress(
        $activity->id, $attempt->userid, 100, 0, 'badtoken'
    );

    $debugbuf = $this->getDebuggingMessages();
    foreach ($debugbuf as $msg) {
        $this->assertStringNotContainsString($attempt->session_token, $msg);
        $this->assertStringNotContainsString('badtoken', $msg);
    }
}
```

## Validation — coverage gates per M6

| Surface | Target |
|---|---|
| `record_view_progress` external | ≥ 90% |
| `watch_tracker_service` | ≥ 90% |
| `session_token_service` | ≥ 90% |
| `custom_completion` | ≥ 85% |
| `playback_service` (mod_fastpix wrapper) | ≥ 85% |
| `mod_form` validation | ≥ 85% |
| `lib.php` callbacks | ≥ 85% |
| `privacy/provider` | ≥ 85% |
| `backup_*` / `restore_*` | ≥ 85% |

CI fails if any target regresses.
