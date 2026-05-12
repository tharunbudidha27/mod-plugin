# Skill 08 — Custom Completion Rule

**Owner agent:** `@completion-grading`.

**When to invoke:** Phase D, step 3.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase D.
- `.claude/rules/completion-grading.md` (CG3, CG4).
- Moodle's `core_completion\activity_custom_completion` documentation.

## Outputs

- `mod/fastpix/classes/completion/custom_completion.php`
- `mod_fastpix_get_completion_active_rule_descriptions($cm)` in `lib.php`

## Steps

### 1. custom_completion class — exactly ONE rule (CG3)

```php
namespace mod_fastpix\completion;

defined('MOODLE_INTERNAL') || die();

use core_completion\activity_custom_completion;

class custom_completion extends activity_custom_completion {

    public static function get_defined_custom_rules(): array {
        return ['completionwatchedpercent'];
    }

    public function get_state(string $rule): int {
        global $DB;

        if ($rule !== 'completionwatchedpercent') {
            throw new \coding_exception("Unknown rule: $rule");
        }

        $activity = $DB->get_record('fastpix', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $threshold = $activity->completion_watch_percent;
        if ($threshold <= 0 || $threshold > 100) {
            return COMPLETION_INCOMPLETE;       // misconfigured; treat as not done
        }

        $attempt = $DB->get_record('fastpix_attempt', [
            'userid' => $this->userid,
            'activity_id' => $activity->id,
        ]);
        if (!$attempt) {
            return COMPLETION_INCOMPLETE;
        }

        $asset = \local_fastpix\service\asset_service::instance()
            ->get_by_internal_id($activity->fastpix_asset_id);
        if (!$asset || !$asset->duration || $asset->duration == 0) {
            return COMPLETION_INCOMPLETE;       // duration unknown → can't compute %
        }

        $percent = ($attempt->watched_seconds / $asset->duration) * 100;
        return $percent >= $threshold ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    public function get_custom_rule_descriptions(): array {
        $threshold = $this->cm->customdata['completion_watch_percent'] ?? 90;
        return [
            'completionwatchedpercent' =>
                get_string('completionwatchedpercent_desc', 'mod_fastpix', $threshold),
        ];
    }

    public function get_sort_order(): array {
        return [
            'completionview',
            'completionwatchedpercent',
            'completionusegrade',
        ];
    }
}
```

### 2. lib.php callback

```php
function mod_fastpix_get_completion_active_rule_descriptions($cm) {
    if (!($cm instanceof cm_info) && !is_object($cm)) {
        return [];
    }
    $threshold = $cm->customdata['completion_watch_percent'] ?? 90;
    if ($threshold > 0) {
        return [get_string('completionwatchedpercent_desc', 'mod_fastpix', $threshold)];
    }
    return [];
}
```

## Validation

- `tests/custom_completion_test.php` covers:
  - Threshold = 0 → COMPLETION_INCOMPLETE (misconfig).
  - Threshold = 100 + watched_seconds = duration → COMPLETION_COMPLETE.
  - Threshold = 80 + watched_seconds = 80% of duration → COMPLETION_COMPLETE (boundary inclusive).
  - Threshold = 80 + watched_seconds = 79.9% of duration → COMPLETION_INCOMPLETE.
  - No attempt row → COMPLETION_INCOMPLETE.
  - asset.duration NULL or 0 → COMPLETION_INCOMPLETE (NOT silently complete).
  - Calling with rule other than `completionwatchedpercent` → coding_exception.
- Coverage target: ≥ 85%.
