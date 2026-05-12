// Player AMD entry point — Phase C placeholder.
//
// The <fastpix-player> element is mounted by the ESM js_init_code in
// view.php (loads hls.js + @fastpix/fp-player as native modules,
// sidestepping Moodle's RequireJS/UMD conflict — see view.php). This
// module exists so the {{#js}} require(['mod_fastpix/player']) block in
// view.mustache resolves cleanly without a RequireJS "Script error",
// and so Phase D's watch_tracker has a stable entry point to bind
// timeupdate / seeked listeners onto the player element.
//
// Intentionally a no-op for v1.0 Phase C. Phase D will expand this to
// wire player events through to mod_fastpix/watch_tracker.

export const init = (cmId) => {
    return cmId;
};
