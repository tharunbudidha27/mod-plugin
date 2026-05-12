# Skill 05 — Watch Tracker AMD Module

**Owner agent:** `@watch-tracker`.

**When to invoke:** Phase D, step 1.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase D.
- `<fastpix-player>` event API (`timeupdate`, `seeked`).
- `.claude/rules/security.md` (S3).

## Outputs

- `mod/fastpix/amd/src/watch_tracker.js`
- Built artifact: `mod/fastpix/amd/build/watch_tracker.min.js` (committed for production)

## Steps

### 1. Locate the wrapping `<div>` and read context

```javascript
import {call as ajaxCall} from 'core/ajax';

let context = null;
let watchedSeconds = 0;
let seekCount = 0;
let timer = null;

export const init = () => {
    const wrap = document.querySelector('[data-region="fastpix-player-wrapper"]');
    if (!wrap) return;

    context = {
        sessionToken: wrap.dataset.sessionToken,
        activityId: parseInt(wrap.dataset.activityId, 10),
        cmId: parseInt(wrap.dataset.cmId, 10),
    };

    const player = wrap.querySelector('fastpix-player');
    if (!player) return;

    player.addEventListener('timeupdate', onTimeUpdate);
    player.addEventListener('seeked', onSeeked);

    timer = setInterval(sendCallback, 10 * 1000);     // every 10s

    window.addEventListener('beforeunload', () => {
        if (timer) clearInterval(timer);
    });
};
```

### 2. Track watched seconds + seek count locally

```javascript
let lastTimeUpdateAt = 0;

const onTimeUpdate = (e) => {
    const t = e.target.currentTime;
    if (t < lastTimeUpdateAt) return;     // ignore backward (seek)
    watchedSeconds = Math.max(watchedSeconds, Math.floor(t));
    lastTimeUpdateAt = t;
};

const onSeeked = (e) => {
    seekCount++;
    lastTimeUpdateAt = e.target.currentTime;
};
```

### 3. Send callback every 10s with retry logic

```javascript
let consecutive401 = 0;
let stopped = false;

const sendCallback = async () => {
    if (stopped) return;

    try {
        await ajaxCall([{
            methodname: 'mod_fastpix_record_view_progress',
            args: {
                activity_id: context.activityId,
                watched_seconds: watchedSeconds,
                client_seek_count: seekCount,
                session_token: context.sessionToken,
            }
        }])[0];
        consecutive401 = 0;
    } catch (e) {
        const status = e.errorcode || e.message;
        if (String(status).includes('401') || String(status).includes('403')) {
            consecutive401++;
            if (consecutive401 >= 2) {
                stopped = true;        // silently stop posting per rules
                if (timer) clearInterval(timer);
            }
        } else if (String(status).includes('5')) {
            // Exponential backoff handled by core/ajax retry config; do nothing extra.
        }
    }
};
```

### 4. Build

`grunt amd` produces the minified build artifact. Check it in.

## Validation

- AMD posts every 10s while video is playing.
- Backward seeks don't decrement `watchedSeconds`.
- Seek count increments on every seek event.
- 401/403 from server → silent retry once, then stop.
- 5xx → core/ajax handles retry.
- No info logged from this module (S6).
- Behat: `completion_grade.feature` and `no_skip_enforcement.feature` exercise this end-to-end.
