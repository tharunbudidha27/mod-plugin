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

    // Phase D smoke-fix #4 — pre-compute every value we inject into the JS
    // init payload so we can interpolate type-safe literals via PHP. Casts
    // protect against unexpected nulls; json_encode covers strings (M10).
    // initial_intervals_json is a server-generated JSON array literal (built
    // by playback_service::resolve_for_view), bounded and safe to emit raw —
    // but fall back to '[]' if for any reason it's missing.
    $cmid             = (int) $cm->id;
    $tokenliteral     = json_encode($state->session_token);
    $intervalsliteral = $state->initial_intervals_json !== '' ? $state->initial_intervals_json : '[]';
    $threshold        = (int) $state->completion_watch_percent;
    $duration         = (int) $state->asset_duration_seconds;
    $completed        = $state->has_completed ? 'true' : 'false';
    $showcaptions     = $state->default_show_captions ? 'true' : 'false';
    // Pill labels — pre-resolved through get_string so the tracker JS can
    // swap them on the in-progress → complete transition without an extra
    // AMD core/str round-trip.
    $statusprocessing = json_encode(get_string('status_processing', 'mod_fastpix'));
    $statuscomplete   = json_encode(get_string('status_complete',   'mod_fastpix'));

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
            // Phase D Slice A Step 1 — resume from last known position.
            // current_position comes from mdl_fastpix_attempt.current_position;
            // the player web component honours start-time at first metadata.
            var startTime = wrap.getAttribute('data-current-position');
            if (startTime && parseFloat(startTime) > 0) {
                el.setAttribute('start-time', startTime);
            }
            el.style.cssText = 'display:block;width:100%;aspect-ratio:16/9;';
            wrap.appendChild(el);

            // No-skip wiring. When the teacher ticks Disable seeking on the
            // activity (mdl_fastpix.no_skip_required = 1), the player needs to:
            //   1) Hide skip-backward / skip-forward buttons (they live in
            //      the player's shadow DOM — must be queried directly).
            //   2) Disable the time-range scrubber (so dragging doesn't seek).
            //   3) Disable keyboard hotkeys that would seek.
            // Server-side fraud check #6 (seek_on_noskip) catches anything
            // that slips through; this is the UX prevention layer.
            if (wrap.getAttribute('data-no-skip-required') === '1') {
                // Mux Player / Media Chrome convention — kills built-in hotkeys.
                el.setAttribute('nohotkeys', '');

                // Set CSS custom props the player MIGHT honour (defence in depth).
                el.style.setProperty('--backward-skip-button', 'none');
                el.style.setProperty('--forward-skip-button', 'none');
                el.style.setProperty('--seek-backward-button', 'none');
                el.style.setProperty('--seek-forward-button', 'none');

                // Pierce shadow DOM. The player wraps Media Chrome web components;
                // the skip buttons + time range are inside the shadow root and
                // can't be hidden by external CSS, so query + hide directly.
                var hideSkipControls = function() {
                    var roots = [];
                    if (el.shadowRoot) { roots.push(el.shadowRoot); }
                    // Some embeds nest another shadow root one level down.
                    if (el.shadowRoot) {
                        el.shadowRoot.querySelectorAll('*').forEach(function(n) {
                            if (n.shadowRoot) { roots.push(n.shadowRoot); }
                        });
                    }
                    var selectors = [
                        'media-seek-forward-button',
                        'media-seek-backward-button',
                        'button[aria-label*=\"forward\" i]',
                        'button[aria-label*=\"backward\" i]',
                        'button[aria-label*=\"seek\" i]',
                        '[role=\"button\"][aria-label*=\"forward\" i]',
                        '[role=\"button\"][aria-label*=\"backward\" i]',
                        '[part*=\"seek-forward\"]',
                        '[part*=\"seek-backward\"]'
                    ];
                    var hit = 0;
                    roots.forEach(function(root) {
                        selectors.forEach(function(sel) {
                            try {
                                root.querySelectorAll(sel).forEach(function(b) {
                                    b.style.display = 'none';
                                    hit++;
                                });
                            } catch (err) { /* ignore selector errors */ }
                        });
                        // Disable scrubbing on the time-range slider so dragging
                        // the playhead does nothing.
                        try {
                            root.querySelectorAll('media-time-range, [part*=\"time-range\"]').forEach(function(tr) {
                                tr.setAttribute('disabled', '');
                                tr.style.pointerEvents = 'none';
                            });
                        } catch (err) { /* ignore */ }
                    });
                    return hit > 0;
                };

                // Shadow DOM may not exist until after the player mounts and
                // upgrades its custom elements. Run on every meaningful event +
                // a few timeouts as a safety net.
                el.addEventListener('loadedmetadata', hideSkipControls);
                el.addEventListener('loadeddata', hideSkipControls);
                el.addEventListener('canplay', hideSkipControls);
                window.setTimeout(hideSkipControls, 200);
                window.setTimeout(hideSkipControls, 800);
                window.setTimeout(hideSkipControls, 2000);
                // Watch for the player to inject controls late.
                if (typeof MutationObserver !== 'undefined' && el.shadowRoot) {
                    new MutationObserver(hideSkipControls).observe(
                        el.shadowRoot,
                        { childList: true, subtree: true }
                    );
                }

                // Keyboard guard — blocks every seek-related key in capture phase.
                var seekKeys = {
                    'ArrowLeft': 1, 'ArrowRight': 1,
                    'KeyJ': 1, 'KeyL': 1,
                    'Home': 1, 'End': 1,
                    'Comma': 1, 'Period': 1,
                    'Digit0': 1, 'Digit1': 1, 'Digit2': 1, 'Digit3': 1, 'Digit4': 1,
                    'Digit5': 1, 'Digit6': 1, 'Digit7': 1, 'Digit8': 1, 'Digit9': 1,
                    'Numpad0': 1, 'Numpad1': 1, 'Numpad2': 1, 'Numpad3': 1, 'Numpad4': 1,
                    'Numpad5': 1, 'Numpad6': 1, 'Numpad7': 1, 'Numpad8': 1, 'Numpad9': 1
                };
                var blockSeekKey = function(e) {
                    if (seekKeys[e.code]) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                };
                el.addEventListener('keydown', blockSeekKey, true);
                wrap.addEventListener('keydown', blockSeekKey, true);
            }

            // Teacher show-captions-by-default wiring. The FastPix Web
            // Player exposes no boolean attribute for this (CC9) — we flip
            // the first subtitles/captions text-track to showing once
            // metadata is loaded. Student keeps full control via the CC
            // button to turn them back off. Resolves the underlying video
            // through the same shadow-DOM dance the tracker uses.
            if ({$showcaptions}) {
                var enableCaptions = function() {
                    var media = el.media
                        || (el.shadowRoot && (el.shadowRoot.querySelector('video') || el.shadowRoot.querySelector('audio')))
                        || el.querySelector('video')
                        || el;
                    var tracks = media && media.textTracks;
                    if (!tracks || tracks.length === 0) { return false; }
                    for (var i = 0; i < tracks.length; i++) {
                        var k = tracks[i].kind;
                        if (k === 'captions' || k === 'subtitles') {
                            tracks[i].mode = 'showing';
                            return true;
                        }
                    }
                    return false;
                };
                el.addEventListener('loadedmetadata', function() {
                    // Track list isn't guaranteed populated on first
                    // loadedmetadata for HLS (sidecar tracks load async),
                    // so retry briefly. Bounded — gives up after ~3s.
                    if (enableCaptions()) { return; }
                    var attempts = 0;
                    var poll = window.setInterval(function() {
                        if (enableCaptions() || ++attempts > 15) {
                            window.clearInterval(poll);
                        }
                    }, 200);
                });
            }

            // Inline coverage tracker. Self-contained — does not depend on
            // the mod_fastpix/watch_tracker AMD module. The player element
            // `el` is in scope from the surrounding mount IIFE so there's
            // no race / polling / MutationObserver. Edge cases per the
            // LMS Progress Tracking design doc (tt.md) — #17 backgrounded
            // tab, #23 ended-snap, #25 replay sticky, #26 buffering,
            // #27 fast playback, #28 mobile pagehide, #29 loop mode.
            //
            // UI: a segmented coverage card with a status pill (no position
            // bar). Coverage is the only signal that drives completion, so
            // it's the only signal we show.
            (function trackBars(player) {
                var bars = document.querySelector('[data-region=\"fastpix-bars\"]');
                if (!bars) { return; }
                var duration  = Number(bars.getAttribute('data-asset-duration')) || {$duration};
                var threshold = Number(bars.getAttribute('data-threshold')) || {$threshold};
                var card    = bars.querySelector('[data-region=\"fastpix-covbar-card\"]');
                var status  = bars.querySelector('[data-region=\"fastpix-covbar-status\"]');
                var seg_w   = bars.querySelector('[data-region=\"fastpix-covbar-watched\"]');
                var seg_n   = bars.querySelector('[data-region=\"fastpix-covbar-needed\"]');
                var seg_b   = bars.querySelector('[data-region=\"fastpix-covbar-bonus\"]');
                var STATUS_PROCESSING = {$statusprocessing};
                var STATUS_COMPLETE   = {$statuscomplete};
                // Server is the source of truth for the interval set on resume —
                // it preserves gappy geometry (e.g. [[0,30],[60,90]]). Earlier
                // code synthesised [[0, coveragePct*duration]] which collapsed
                // gaps; the defensive guard in watch_tracker_service::record_progress
                // covered for it, but the local coverage bar over-counted during
                // the resume→first-persist window.
                var watched = {$intervalsliteral};
                if (!Array.isArray(watched)) { watched = []; }
                var lastTime = 0;
                var isSeeking = false;
                var seekCount = 0;
                var hasCompleted = {$completed};
                var endedFired  = false;
                function addInterval(arr, a, b) {
                    if (b <= a) { return arr; }
                    var merged = arr.concat([[a, b]]).sort(function(x, y) { return x[0] - y[0]; });
                    var out = [];
                    for (var i = 0; i < merged.length; i++) {
                        var cur = merged[i];
                        if (out.length && cur[0] - out[out.length - 1][1] <= 0.01) {
                            out[out.length - 1][1] = Math.max(out[out.length - 1][1], cur[1]);
                        } else {
                            out.push([cur[0], cur[1]]);
                        }
                    }
                    return out;
                }
                function coverageSeconds() {
                    var s = 0;
                    for (var i = 0; i < watched.length; i++) { s += watched[i][1] - watched[i][0]; }
                    return s;
                }
                function paintBar(coveragePct) {
                    if (!seg_w || !seg_n || !seg_b) { return; }
                    var watchedWidth = Math.max(0, Math.min(100, coveragePct));
                    var neededWidth;
                    var bonusWidth;
                    if (watchedWidth >= threshold) {
                        // Past the threshold — no amber gap; bonus zone is whatever
                        // remains beyond the watched %.
                        neededWidth = 0;
                        bonusWidth  = Math.max(0, 100 - watchedWidth);
                    } else {
                        neededWidth = Math.max(0, threshold - watchedWidth);
                        bonusWidth  = Math.max(0, 100 - threshold);
                    }
                    seg_w.style.width = watchedWidth + '%';
                    seg_n.style.width = neededWidth + '%';
                    seg_b.style.width = bonusWidth  + '%';
                }
                function repaint() {
                    var cov = duration > 0 ? Math.min(100, (coverageSeconds() / duration) * 100) : 0;
                    var crossed = (cov >= threshold) || hasCompleted;
                    paintBar(cov);
                    if (crossed && card && !card.classList.contains('is-complete')) {
                        var firstTransition = !hasCompleted;
                        hasCompleted = true;
                        card.classList.add('is-complete');
                        if (status) { status.textContent = STATUS_COMPLETE; }
                        // Flush the 0→1 transition immediately so completion +
                        // grade fire without waiting for the 10s heartbeat.
                        if (firstTransition) { persist(false); }
                    }
                }
                player.addEventListener('loadedmetadata', function() {
                    if (player.duration && isFinite(player.duration)) {
                        duration = player.duration;
                        bars.setAttribute('data-asset-duration', duration);
                    }
                    repaint();
                });
                player.addEventListener('play',    function() { isSeeking = false; lastTime = Number(player.currentTime || 0); });
                player.addEventListener('seeking', function() { isSeeking = true; });
                player.addEventListener('seeked',  function() { isSeeking = false; seekCount += 1; lastTime = Number(player.currentTime || 0); repaint(); });
                player.addEventListener('waiting', function() { lastTime = Number(player.currentTime || 0); });
                player.addEventListener('pause',   function() { repaint(); persist(); });
                player.addEventListener('timeupdate', function() {
                    var t = Number(player.currentTime || 0);
                    if (isSeeking) { lastTime = t; repaint(); return; }
                    var delta = t - lastTime;
                    if (delta < 0) { lastTime = t; repaint(); return; }
                    var rate = Number(player.playbackRate || 1);
                    var boost = document.visibilityState === 'hidden' ? 2.0 : 1.0;
                    var cap = Math.max(1.5, rate * 1.5) * boost;
                    if (delta > 0 && delta < cap) {
                        watched = addInterval(watched, lastTime, t);
                    }
                    lastTime = t;
                    repaint();
                });
                player.addEventListener('ended', function() {
                    if (isFinite(player.duration) && player.duration > 0) {
                        // tt.md edge case #23 — snap the final interval up to
                        // duration so a natural end at 99.97% (float precision)
                        // still rounds to 100% coverage. Does NOT set
                        // hasCompleted — completion is coverage-only.
                        watched = addInterval(watched, lastTime, player.duration);
                        lastTime = player.duration;
                    }
                    endedFired = true;
                    // Completion is decided in repaint() purely on coverage
                    // vs threshold. Reaching the player's natural end is not
                    // a shortcut to completion.
                    repaint();
                    persist();
                });
                function buildArgs() {
                    return {
                        cmid: {$cmid},
                        session_token: {$tokenliteral},
                        watched_intervals: JSON.stringify(watched),
                        current_position: Number(player.currentTime || 0),
                        client_seek_count: seekCount,
                        // Only true when the player's 'ended' event has fired —
                        // never as a proxy for hasCompleted. Server uses this
                        // independently of coverage% for the completion gate.
                        ended_fired: endedFired
                    };
                }
                function persist(useBeacon) {
                    if (typeof M === 'undefined' || !M.cfg) { return; }
                    var args = buildArgs();
                    var snapshot = JSON.stringify(args);
                    try { window.localStorage.setItem('mod_fastpix_attempt_' + {$cmid}, snapshot); } catch (e) {}
                    // sendBeacon for unload — guaranteed delivery.
                    if (useBeacon && navigator.sendBeacon) {
                        var beaconBody = JSON.stringify([{
                            index: 0, methodname: 'mod_fastpix_record_view_progress', args: args
                        }]);
                        var beaconUrl = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey='
                                      + encodeURIComponent(M.cfg.sesskey);
                        try {
                            navigator.sendBeacon(beaconUrl, new Blob([beaconBody], { type: 'application/json' }));
                            return;
                        } catch (e) { /* fall through */ }
                    }
                    // Normal heartbeat: use core/ajax — handles sesskey + format + retries.
                    if (!window.require) { return; }
                    require(['core/ajax'], function(Ajax) {
                        Ajax.call([{
                            methodname: 'mod_fastpix_record_view_progress',
                            args: args
                        }])[0].then(function(response) {
                            try { window.localStorage.removeItem('mod_fastpix_attempt_' + {$cmid}); } catch (e) {}
                            if (response && response.completion_state === 'complete') {
                                hasCompleted = true;
                                repaint();
                            }
                            return null;
                        }).catch(function(err) {
                            if (window.console) {
                                console.warn('[mod_fastpix] persist failed', err && err.errorcode, err && err.message);
                            }
                        });
                    });
                }
                window.setInterval(function() { persist(false); }, 10000);
                document.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') { persist(true); }
                });
                window.addEventListener('pagehide', function() { persist(true); });
                repaint();
            })(el);
        })();",
        true
    );
}

/** @var \mod_fastpix\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_fastpix');

echo $OUTPUT->header();

// Moodle 4.x renders BOTH the activity title and the activity intro
// automatically via $PAGE->activityheader. Echoing $OUTPUT->heading() or
// format_module_intro() here would render each of them a second time
// (visible as a duplicated title + description block below the header).

echo $renderer->render_state($state);

echo $OUTPUT->footer();
