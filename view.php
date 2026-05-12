<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('fastpix', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('fastpix', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/fastpix:view', $context);

$cminfo = cm_info::create($cm);

$state = \mod_fastpix\service\playback_service::instance()->resolve_for_view(
    $activity,
    (int)$USER->id,
    $cminfo
);

$PAGE->set_url('/mod/fastpix/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Event fires AFTER set_context so observers see populated $PAGE state.
\mod_fastpix\event\activity_viewed::create_from_activity($activity, $context)->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Processing-state UX: belt-and-braces auto-reload.
// 1) Meta-refresh every 8s as a JS-free fallback (works even if AMD fails).
// 2) AMD poller (mod_fastpix/processing_state_poller) reloads sooner on state
//    transition.
// 3) no-store cache headers prevent the browser from showing a stale
//    "preparing" HTML response after the asset has gone ready (the original
//    "have to hard-refresh" symptom).
if ($state instanceof \mod_fastpix\dto\view_state_processing) {
    $PAGE->set_periodic_refresh_delay(8);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// The FastPix Web Player web component (<fastpix-player>) needs a global
// `window.Hls` available before its connectedCallback runs. The player has
// its own hls.js auto-loader, but it appends a plain <script> tag which under
// Moodle's RequireJS hits the UMD `define.amd` branch — RequireJS captures
// the anonymous `define(factory)` and `window.Hls` never gets assigned. The
// player then renders as an inert black box with NO console error.
//
// Strategy: load both hls.js and the player as native ES modules via dynamic
// import(). ESM is parsed and executed outside RequireJS's AMD context, so
// the UMD-AMD conflict cannot trigger. We assign hls.js's default export to
// window.Hls before importing the player, so the player's internal hls
// auto-loader short-circuits via its `if (window.Hls) return Promise.resolve()`
// guard (verified against @fastpix/fp-player@1.0.17 source).
//
// The mustache template renders the wrapper WITHOUT the <fastpix-player>
// child; this init code mounts it after both deps are ready, copying
// playback attributes from data-* attrs on the wrapper. Skipped on
// processing/error states (no element to mount).
if ($state instanceof \mod_fastpix\dto\view_state_player) {
    $playerurl = json_encode(\mod_fastpix\service\playback_service::PLAYER_LIB_URL);
    $hlsurl    = json_encode(\mod_fastpix\service\playback_service::HLS_LIB_URL);
    $PAGE->requires->js_init_code(
        "(async function() {
            var wrap = document.querySelector('[data-region=\"fastpix-player-wrapper\"]');
            if (!wrap) { return; }
            try {
                if (!window.Hls) {
                    var hlsMod = await import($hlsurl);
                    window.Hls = hlsMod.default || hlsMod.Hls || hlsMod;
                }
                if (!window.customElements.get('fastpix-player')) {
                    await import($playerurl);
                }
            } catch (err) {
                if (window.console) { console.error('[mod_fastpix] player load failed', err); }
                return;
            }
            if (wrap.querySelector('fastpix-player')) { return; }
            var el = document.createElement('fastpix-player');
            el.setAttribute('playback-id', wrap.getAttribute('data-playback-id'));
            el.setAttribute('token', wrap.getAttribute('data-playback-token'));
            if (wrap.getAttribute('data-drm-required') === '1') {
                el.setAttribute('drm-token', wrap.getAttribute('data-playback-token'));
            }
            el.setAttribute('stream-type', 'on-demand');
            var accent = wrap.getAttribute('data-accent-color');
            if (accent) { el.setAttribute('accent-color', accent); }
            var title = wrap.getAttribute('data-video-title');
            if (title) { el.setAttribute('metadata-video-title', title); }
            el.style.cssText = 'display:block;width:100%;aspect-ratio:16/9;';
            wrap.appendChild(el);
        })();",
        true
    );
}

/** @var \mod_fastpix\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_fastpix');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name));

if (!empty($activity->intro)) {
    echo $OUTPUT->box(format_module_intro('fastpix', $activity, $cm->id), 'generalbox', 'intro');
}

echo $renderer->render_state($state);

echo $OUTPUT->footer();
