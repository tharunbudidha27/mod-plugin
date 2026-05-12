// Processing-state poller (§13.2). Plain HTTP polling — no WebSocket.
//
// Polls local_fastpix_get_upload_status. The web service returns {state, ...}
// (NOT {status, ...}) — `state` is the upload-session state. When state
// transitions away from 'pending' (i.e. the projector has linked the asset),
// we reload and let view.php re-resolve. If the asset is ready, view.php
// renders the player; if still transcoding, we land back here and keep
// polling. sessionStorage caps total reloads to prevent loops.

import {call as fetchMany} from 'core/ajax';

const POLL_INTERVAL_MS = 5000;
const FIRST_POLL_DELAY_MS = 3000;
const MAX_POLLS = 24;             // ~2 minutes of polling
const MAX_RELOADS_PER_SESSION = 10;
const RELOAD_KEY = 'mod_fastpix_processing_reloads';

let pollsRemaining = MAX_POLLS;
let timer = null;

const reloadOnce = () => {
    let count = 0;
    try { count = parseInt(window.sessionStorage.getItem(RELOAD_KEY) || '0', 10); } catch (e) { /* private mode */ }
    if (count >= MAX_RELOADS_PER_SESSION) {
        revealFallback();
        return;
    }
    try { window.sessionStorage.setItem(RELOAD_KEY, String(count + 1)); } catch (e) { /* ignore */ }
    window.location.reload();
};

const tick = (uploadSessionId) => {
    if (uploadSessionId === null || uploadSessionId === undefined) {
        revealFallback();
        return;
    }
    pollsRemaining -= 1;

    fetchMany([{
        methodname: 'local_fastpix_get_upload_status',
        args: {session_id: uploadSessionId},
    }])[0].then((response) => {
        // Asset row exists once the projector flips state away from 'pending'.
        // Reload and let view.php decide between view_state_processing
        // (asset still transcoding) and view_state_player (asset ready).
        if (response && response.state && response.state !== 'pending') {
            reloadOnce();
            return;
        }
        if (pollsRemaining <= 0) {
            revealFallback();
            return;
        }
        timer = window.setTimeout(() => tick(uploadSessionId), POLL_INTERVAL_MS);
        return null;
    }).catch(() => {
        if (pollsRemaining <= 0) {
            revealFallback();
            return;
        }
        timer = window.setTimeout(() => tick(uploadSessionId), POLL_INTERVAL_MS);
    });
};

const revealFallback = () => {
    const node = document.querySelector('[data-region="fastpix-processing-fallback"]');
    if (node) {
        node.removeAttribute('hidden');
    }
};

// Idempotency guard — if init() is called more than once on the same DOM
// (re-render via Templates.replaceNodeContents, hot-reload, etc.) we must not
// schedule duplicate timers. Mirrors upload_widget.js's data-fastpix-wired
// pattern.
let initWired = false;

export const init = (cmid, uploadSessionId) => {
    if (initWired) {
        return;
    }
    initWired = true;
    if (timer !== null) {
        window.clearTimeout(timer);
    }
    pollsRemaining = MAX_POLLS;
    timer = window.setTimeout(() => tick(uploadSessionId), FIRST_POLL_DELAY_MS);
};
