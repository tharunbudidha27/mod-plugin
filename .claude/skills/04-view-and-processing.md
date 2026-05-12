# Skill 04 — view.php + Processing-State UX

**Owner agent:** `@playback-view`.

**When to invoke:** Phase C.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase C.
- `\local_fastpix\service\playback_service::resolve()` signature from `01-local-fastpix.md`.
- `.claude/rules/security.md` (S3, S6).
- `.claude/rules/consumer-contract.md` (CC1, CC4).

## Outputs

- `mod/fastpix/view.php` (full)
- `mod/fastpix/templates/view.mustache`, `processing.mustache`, `error.mustache`
- `mod/fastpix/classes/output/view_renderer.php`
- `mod/fastpix/classes/service/playback_service.php` (mod_fastpix's wrapper)
- `mod/fastpix/amd/src/processing_state_poller.js`
- `\mod_fastpix\event\activity_viewed`

## Steps

### 1. view.php — auth dance + state branch

```php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('fastpix', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('fastpix', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/fastpix:view', $context);

$PAGE->set_url('/mod/fastpix/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));

// Resolve playback (or get error/processing state).
$playback = \mod_fastpix\service\playback_service::instance()
    ->resolve_for_view($activity, $USER->id);

// Trigger Moodle's "viewed" event.
\mod_fastpix\event\activity_viewed::create([
    'context' => $context,
    'objectid' => $activity->id,
])->trigger();

// Update completion-tracks-views.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name));

$renderer = $PAGE->get_renderer('mod_fastpix');
echo $renderer->render($playback);    // dispatches to view/processing/error template

echo $OUTPUT->footer();
```

### 2. playback_service::resolve_for_view

Wraps `\local_fastpix\service\playback_service::resolve` with activity-level concerns:

```php
namespace mod_fastpix\service;

class playback_service {
    public function resolve_for_view(stdClass $activity, int $userid): \mod_fastpix\dto\view_state {
        // 1. Look up asset via local_fastpix's asset_service (CC1, CC5).
        $asset = \local_fastpix\service\asset_service::instance()
            ->get_by_internal_id($activity->fastpix_asset_id);

        if (!$asset || $asset->deleted_at) {
            return new view_state_error('videounavailable');
        }
        if ($asset->status !== 'ready') {
            return new view_state_processing($activity, $asset);
        }

        // 2. Get-or-create attempt row.
        $attempt = $this->get_or_create_attempt($activity, $userid, $asset);

        // 3. Mint playback token via local_fastpix.
        $payload = \local_fastpix\service\playback_service::instance()
            ->resolve($asset->playback_id, $userid);

        return new view_state_player($activity, $asset, $attempt, $payload);
    }

    private function get_or_create_attempt($activity, $userid, $asset): stdClass {
        global $DB;
        $existing = $DB->get_record('fastpix_attempt', [
            'userid' => $userid, 'activity_id' => $activity->id,
        ]);
        if ($existing && $existing->session_start_ts > time() - (4 * HOURSECS)) {
            return $existing;        // valid session
        }
        // Mint new session.
        $session_token = \mod_fastpix\service\session_token_service::instance()
            ->issue($userid, $activity->id, time());
        $row = (object)[
            'userid' => $userid,
            'activity_id' => $activity->id,
            'asset_id' => $asset->id,
            'session_token' => $session_token,
            'session_start_ts' => time(),
            'watched_seconds' => 0,
            'seek_count' => 0,
            'fraud_count' => 0,
            'completion_state' => 'in_progress',
        ];
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('fastpix_attempt', $row);
        } else {
            $row->id = $DB->insert_record('fastpix_attempt', $row);
        }
        return $row;
    }
}
```

### 3. view.mustache — wrapping `<div>` with data attributes (CC4)

```mustache
<div data-region="fastpix-player-wrapper"
     data-session-token="{{session_token}}"
     data-activity-id="{{activity_id}}"
     data-asset-id="{{asset_id}}"
     data-cm-id="{{cm_id}}">
    <fastpix-player
        playback-id="{{playback_id}}"
        playback-token="{{playback_token}}"
        {{#accent_color}}accent-color="{{accent_color}}"{{/accent_color}}
        metadata-video-title="{{video_title}}"
        {{#default_show_captions}}default-show-captions{{/default_show_captions}}>
    </fastpix-player>
</div>
{{#js}}
require(['mod_fastpix/watch_tracker'], function(t) { t.init(); });
require(['mod_fastpix/player'], function(p) { p.init(); });
{{/js}}
```

### 4. processing.mustache

```mustache
<div data-region="fastpix-processing">
    <p>{{#str}}processing_message, mod_fastpix{{/str}}</p>
    <div class="progress" role="progressbar" aria-label="{{#str}}processing_progress_aria, mod_fastpix{{/str}}">
        <div class="progress-bar progress-bar-striped progress-bar-animated"
             style="width: 100%"></div>
    </div>
</div>
{{#js}}
require(['mod_fastpix/processing_state_poller'], function(p) {
    p.init({{cm_id}}, {{activity_id}});
});
{{/js}}
```

### 5. error.mustache

```mustache
<div class="alert alert-warning" role="alert">
    {{#str}}error_{{reason_key}}, mod_fastpix{{/str}}
</div>
```

`reason_key` is one of: `videounavailable`, `drm_unsupported`, `capability_lost`. Strings are user-facing, no system internals (S9).

### 6. processing_state_poller.js

Polls `local_fastpix_get_upload_status` every 30s, max 10 polls. On `status='ready'`, reload page. On max reached, show "Refresh manually" button.

```javascript
import {call as ajaxCall} from 'core/ajax';

const POLL_INTERVAL_MS = 30 * 1000;
const MAX_POLLS = 10;

export const init = (cmid, activityId) => {
    let polls = 0;
    const poll = async () => {
        if (polls++ >= MAX_POLLS) {
            showManualRefresh();
            return;
        }
        try {
            const result = await ajaxCall([{
                methodname: 'local_fastpix_get_upload_status',
                args: { activity_id: activityId }
            }])[0];
            if (result.status === 'ready') {
                window.location.reload();
                return;
            }
        } catch (e) { /* swallow; will retry */ }
        setTimeout(poll, POLL_INTERVAL_MS);
    };
    setTimeout(poll, POLL_INTERVAL_MS);
};
```

## Validation

- Student opens activity, sees player, clicks play, video plays.
- Asset with `status !== 'ready'` shows processing UI; poller transitions to player when ready.
- Asset deleted shows "Video unavailable."
- ARIA labels present on player wrapper and progress bar; keyboard navigation works.
- `tests/playback_service_test.php` ≥ 85% coverage.
- Behat: `student_view.feature` passes.
