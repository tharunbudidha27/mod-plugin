# mod_fastpix — Engineering Plan v1.0

**Audience:** engineering team, project lead, design partners
**Status:** Draft, ready for sign-off
**Source of truth:** FastPix × Moodle Plugin Design Doc v5.3 (2026-04-30)
**Dependency:** local_fastpix v0.2.0+ (foundation plugin must be GA before this work begins)

---

## 1. Scope

`mod_fastpix` is the **activity module** in the four-plugin system. It owns
everything the student and teacher actually see and interact with for FastPix
videos inside a course. It depends on `local_fastpix` for gateway access,
asset metadata, credentials, and webhook ingestion — those are not in this
plan.

### What `mod_fastpix` owns (per design doc §6.1, §6.5)

- `mdl_fastpix` — activity instances table (one row per activity in a course)
- `mdl_fastpix_attempt` — per-user watch-attempt table (the gradebook source)
- Activity edit form (`mod_form.php`) — what teachers see when adding/editing a video activity
- Activity view (`view.php`) — what students see when launching a video
- Custom completion rule via `core_completion\activity_custom_completion`
- Gradebook integration via `grade_update()`
- Backup/restore handlers via `FEATURE_BACKUP_MOODLE2`
- Watch-progress endpoint (`record_view_progress` web service) with the six
  server-side validation checks
- Session token issuance and validation (HMAC-bound to user × activity × session_start)
- AMD module — wires `<fastpix-player>` timeupdate and seeked events to the callback endpoint
- Six fraud checks plus the seek-counter (§10.3)
- Capability declarations: `mod/fastpix:addinstance`, `mod/fastpix:view`,
  `mod/fastpix:viewallattempts`

### What is explicitly NOT in scope (per design doc §2.2 + ADR-003)

- Completion reconciler — deferred to year 2 (ADR-003)
- Per-user dynamic watermarks — removed in v5.3 (ADR-005 withdrawn)
- Offline DRM playback in mobile (no persistent license handling)
- Course-level media gallery / shared institutional library
- Live streaming, browser-based recording, simulcast
- Drop-off curves and per-chapter analytics inside Moodle (FastPix dashboard handles)
- AI moderation (NSFW/profanity), transcript-based search
- Multi-tenancy (no workspace_id columns)
- Cross-FastPix-account portability of backed-up courses (ADR-010 — "Video unavailable" message)
- Direct paste of FastPix HLS URLs (ADR-008 — documented limitation)

If anyone asks for these during the build, the answer is "v2.0" and the work stops.

---

## 2. Goals & success criteria

Per design doc §2.1, `mod_fastpix` is the plugin that delivers G1, G2, and G3:

| # | Goal | How `mod_fastpix` delivers | Pass criterion |
|---|------|----------------------------|----------------|
| G1 | DRM playback works in Chrome, Safari, Firefox, Edge, and Moodle Mobile WebView | Renders `<fastpix-player>` with playback token from gateway; Moodle Mobile compatibility via WebView (ADR-007) | p99 playback start ≤ 4 s on 25 Mbps |
| G2 | Watch-% completion writes to gradebook accurately | `record_view_progress` endpoint + 6 fraud checks + completion API + `grade_update()` | ≥ 99.5% on 100-attempt sample reconciliation per RC |
| G3 | Setup time install → first video playing | mod_form upload affordance + processing-state UX | p50 ≤ 20 minutes on clean Moodle 4.5 |

G4 (Plugins Directory approval) is mostly a process concern but `mod_fastpix`
must pass `moodle-plugin-ci` cleanly.

---

## 3. Phased delivery plan

Total scope: **~7 weeks engineering effort + 4 weeks pilot/RC = 11 weeks calendar.**

### Phase A — Foundation (Week 1)

Get a Moodle activity registered and clickable. No video logic yet — pure
plumbing. Target: end of Phase A, an empty `mod_fastpix` activity can be
added to a course and saved.

**Deliverables:**

- Plugin scaffold: `version.php` (2026XXXXX), `lib.php` with feature constants,
  `db/install.xml`, `db/access.php`, `lang/en/mod_fastpix.php`
- `lib.php` features:
  - `FEATURE_GRADE_HAS_GRADE = true` (§13.3)
  - `FEATURE_COMPLETION_HAS_RULES = true` (§13.3)
  - `FEATURE_BACKUP_MOODLE2 = true` (§4.2)
  - `MOD_PURPOSE_ASSESSMENT` — appears under "Assessment" alongside Quiz/Assignment
  - `mod_fastpix_supports($feature)` returning the standard set
- `db/install.xml`:
  - `mdl_fastpix` activity table per design doc §6.5
  - `mdl_fastpix_attempt` table per design doc §6.5 (full schema below)
  - Indexes: `UNIQUE(user_id, activity_id)`, `(activity_id, completion_state)`
- `db/access.php`:
  - `mod/fastpix:addinstance` (teacher-level; archetype `editingteacher`)
  - `mod/fastpix:view` (student-level; archetype `student`)
  - `mod/fastpix:viewallattempts` (teacher-level; for gradebook overrides)
- Empty `mod_form.php` extending `moodleform_mod` — title + intro fields only
- Empty `view.php` — placeholder "Hello, video activity"
- Lang strings for activity name, description, capability descriptions
- `pix/icon.svg` and `pix/monologo.svg` (required by Moodle 4.5 themes)

**Exit criterion:** can add a `mod_fastpix` activity to a test course, save it, see it on the course page, click it, get the placeholder view. `moodle-plugin-ci` passes.

**Risk:** capability namespace conflicts with `local_fastpix`. Resolution per ADR-012 (already decided): `mod/fastpix:uploadmedia` lives in `mod_fastpix`, not `local_fastpix`. Confirm before Phase B.

### Phase B — Activity edit form (Week 2)

Teacher can configure an activity by uploading a video or pasting a URL.

**Deliverables:**

- `mod_form.php` with three fieldsets:
  1. **Standard activity fields** — name, intro, course module visibility (Moodle defaults)
  2. **Video source** — two-tab control:
     - Tab 1: **Upload video** — direct chunked upload via `local_fastpix` upload service (calls `local_fastpix_create_upload_session`)
     - Tab 2: **Paste URL** — URL pull (calls `local_fastpix_create_url_pull_session`)
  3. **Playback options:**
     - Access policy (inherits from `local_fastpix` default; can override per-activity)
     - DRM required (if site has DRM enabled; gated)
     - No-skip enforcement (boolean — drives §10.3 check #6)
     - Auto-generate captions toggle
  4. **Completion** — standard Moodle completion + custom "% watched" threshold (default 90%)
  5. **Grade** — standard grade settings (point/scale, max grade)
- TinyMCE upload widget integration in the edit form (calls into `tiny_fastpix` if available; falls back to direct file picker if not)
- AMD module for upload progress UI: chunked upload progress bar, error states, retry button
- Lang strings for every form field, every error message, every help icon
- Pre-save validation:
  - Reject if both upload and URL are empty
  - Reject if URL is malformed (delegate to `local_fastpix`'s SSRF guard)
  - Reject if completion threshold is outside (0, 100]
- Post-save behavior:
  - On upload tab + new file: store `upload_session_id` in activity row; `fastpix_id` is null until webhook arrives
  - On URL tab: same — `fastpix_id` filled by webhook
  - On edit (existing activity): teacher can swap the video; old asset is soft-deleted via `local_fastpix` privacy path

**Exit criterion:** teacher can upload a video, save the activity, see it appear in `mdl_fastpix_asset` (after webhook), see it on the course page. Behat test for happy path passes.

**Risk:** the upload affordance UX has to handle three async states (uploading, awaiting webhook, ready) without confusing the teacher. Design doc §13.2 specifies the processing-state UX — implement exactly per spec.

### Phase C — Student playback (Week 3)

Student clicks the activity, sees the player, can watch. No tracking yet.

**Deliverables:**

- `view.php` rendering `<fastpix-player>` with:
  - Required: `playback-id`, `playback-token` (DRM JWT from gateway, TTL 300s)
  - Conditional: `accent-color`, `metadata-video-title`, `default-show-captions`
  - Wrapper `<div>` carrying `data-session-token`, `data-activity-id`, `data-asset-id`
- `playback_service::resolve($activity_id, $userid)` — returns asset_id, playback_id, drm_required, signed JWT, session_token
- Session token minted per design doc §10.2: `HMAC(user_id || activity_id || session_start_ts, mod_fastpix.session_secret)`. TTL 4 hours; reissued on every page reload.
- `mdl_fastpix_attempt` row created on first view (or fetched on revisit; `UNIQUE(user_id, activity_id)` guarantees one row)
- Capability check: `require_login` + `require_capability('mod/fastpix:view', $context)`
- Processing-state UX (§13.2):
  - If `asset->status !== 'ready'`: show "This video is still processing. It usually takes 1–5 minutes. Refresh in a moment."
  - AMD module polls `local_fastpix_get_upload_status` every 30 seconds — not WebSocket, intentionally simple (per doc)
  - No email notification in v1.0 (per doc)
- Error states:
  - Asset not found → "Video unavailable" (per ADR-010, also catches cross-FastPix-account backup case)
  - DRM required but client doesn't support DRM → render fallback message
- Mustache templates for view + processing + error states
- Activity viewed event: `\mod_fastpix\event\activity_viewed::trigger()` per §4.3

**Exit criterion:** student logs in, opens activity, sees video player, can click play, video plays. Behat test for "happy-path watch" passes.

**Risk:** browser DRM compatibility matrix. v5.3 §11.3 T2 acknowledges L3 screen-recording as an unmitigated v1.0 threat. Document this in the README; do not try to mitigate it. (Year-2 escalation: server-side burn-in.)

### Phase D — Watch tracking & completion (Week 4)

The whole gradebook story: callbacks, fraud checks, completion rule.

**Deliverables:**

- AMD module `mod_fastpix/watch_tracker`:
  - Subscribes to `<fastpix-player>` `timeupdate` event
  - Subscribes to `<fastpix-player>` `seeked` event (for §10.3 check #6)
  - POSTs every 10 seconds to `mod_fastpix_record_view_progress` (web service)
  - Includes: `activity_id`, `watched_seconds`, `session_token`, `client_seek_count`
  - On 401/403: token expired or invalid — silently retry once, then stop posting (don't disrupt UX)
  - On 5xx: exponential backoff with jitter, max 3 retries
- External function `mod_fastpix\external\record_view_progress` per §6.6:
  - Validates sesskey, capability, session token
  - Implements all six checks from §10.3 verbatim:
    1. Cannot exceed video duration → fraud `exceeds_duration`
    2. Cannot exceed wall-clock since session start (10s tolerance) → fraud `exceeds_wall_clock`
    3. Monotonic watched_seconds → fraud `regression`
    4. Plausible gain (≤ elapsed wall-clock + 10s) → fraud `implausible_gain`
    5. Capability still held → fraud `capability_lost`
    6. Seek-counter monotonic; on no-skip assets, any forward seek → fraud `seek_on_noskip`
  - Updates `mdl_fastpix_attempt` (single UPDATE by primary key — design doc §7 confirms ~50 QPS sustainable)
  - Calls `completion_info::update_state($activity)` after every callback
- Custom completion rule class `mod_fastpix\completion\custom_completion`:
  - Extends `core_completion\activity_custom_completion`
  - Single rule: `completionwatchedpercent` — reads `watched_seconds` from attempt vs `duration` from asset
  - Returns `COMPLETION_COMPLETE` if `watched_seconds / asset.duration >= threshold`, else `COMPLETION_INCOMPLETE`
- Gradebook write via `grade_update($source, $courseid, $itemtype, $itemmodule, $iteminstance, $itemnumber, $grades, $itemdetails)`:
  - Called from `record_view_progress` only when `completion_state` transitions
  - Never write `mdl_grade_grades` directly (per §4.2)
- Watch-milestone event: `\mod_fastpix\event\watch_milestone::trigger()` at 25/50/75/100% (per §4.2 events list)
- Fraud surfacing: `mdl_fastpix_attempt.fraud_count > 20` shows a row badge in the gradebook view (per §10.3)

**Exit criterion:** student watches a video to completion threshold, gradebook shows graded entry, completion state updates. PHPUnit tests cover all 6 fraud paths. Behat test for full "watch to completion + gradebook write" passes.

**Risk:** the 10-second tolerance in checks 2 and 4 is the abuse ceiling per §10.4. Do not loosen it without an ADR. The seek-counter (§10.3 check #6) is non-optional — without it, no-skip mode doesn't work.

### Phase E — Backup, restore, GDPR (Week 5)

Activity survives course duplication and data subject requests.

**Deliverables:**

- `backup/` directory with:
  - `moodle2/backup_fastpix_activity_task.class.php`
  - `moodle2/backup_fastpix_stepslib.php` — backs up `mdl_fastpix` activity row + `mdl_fastpix_attempt` rows; references `fastpix_id` (asset reference, not bytes)
  - `moodle2/restore_fastpix_activity_task.class.php`
  - `moodle2/restore_fastpix_stepslib.php` — restores activity row; on asset lookup miss, sets activity to "Video unavailable" state per ADR-010
- Privacy provider integration (in `local_fastpix` already, but `mod_fastpix` adds activity-level metadata):
  - `\mod_fastpix\privacy\provider` declaring `mdl_fastpix_attempt` columns
  - `delete_data_for_user` deletes attempts for the user
  - `export_user_data` includes activity history
  - `get_users_in_context` enumerates users with attempts in a course context
- Recycle bin support: when activity is deleted via Moodle's recycle bin, `mod_fastpix_pre_course_module_delete` hook calls `local_fastpix` to soft-delete the asset
- Behat test: backup a course with a video activity, restore to a different course, verify video plays (same FastPix account) OR shows "Video unavailable" (different FastPix account, ADR-010)

**Exit criterion:** course backup/restore works for same-account scenarios. Different-account scenarios show the documented limitation message gracefully. GDPR data export round-trip test passes (exports, then deletes, then attempts to view → sees nothing).

**Risk:** restore behavior across FastPix accounts is explicitly documented as a limitation (ADR-010). Don't over-engineer it. Show the message. Move on.

### Phase F — Polish, hardening, documentation (Week 6)

Production-readiness work that's not feature delivery.

**Deliverables:**

- Full lang file review — no `[[lang_key]]` placeholders anywhere in UI
- Mustache template accessibility: ARIA labels on player wrapper, keyboard navigation for processing-state polling, visible focus rings
- README.md per `local_fastpix` precedent: install, configuration, capability matrix, known limitations linking to STATUS.md
- STATUS.md mirroring `local_fastpix` format
- `db/upgrade.php` skeleton (will be empty for v1.0 but must exist per Moodle convention)
- `tests/` directory:
  - `lib_test.php` — feature flags, supports() function
  - `mod_form_test.php` — validation rules
  - `record_view_progress_test.php` — all 6 fraud checks (boundary tests like Tier 1 in `local_fastpix`)
  - `custom_completion_test.php` — threshold edge cases
  - `backup_restore_test.php` — round-trip with mock asset
  - Coverage targets per design doc §12.5: watch_tracker_service ≥ 90%
- Behat tests:
  - `tests/behat/add_activity.feature` — teacher adds video activity
  - `tests/behat/student_view.feature` — student watches video
  - `tests/behat/no_skip_enforcement.feature` — no-skip flag enforcement
  - `tests/behat/completion_grade.feature` — completion + gradebook writeback (per §12.5)
- `moodle-plugin-ci` configuration in CI (GitHub Actions / GitLab CI)
- Manual installation against a clean Moodle 4.5 — measure setup time, confirm G3 (≤ 20 min p50)

**Exit criterion:** all tests green, `moodle-plugin-ci` clean, manual install timed at ≤ 20 minutes. Tag v1.0.0-rc1.

### Phase G — Pilot RC1 (Weeks 7–8)

Ship to design partners. Watch what breaks.

**Deliverables:**

- Deploy v1.0.0-rc1 to 3 design partner Moodle sites per §14.1
- Monitor `mdl_fastpix_attempt` for fraud_count > 20 patterns (early signal of validation gaps)
- Manual reconciliation: 100-attempt sample per RC, comparing `mdl_fastpix_attempt.watched_seconds` against FastPix Data API (per G2 measurement)
- Pilot success criteria per §14.4:
  - Zero P0/P1 bugs
  - 100-attempt sample reconciliation ≥ 99.5% on all 3 sites for 14 days
  - Setup time p50 ≤ 20 min confirmed across partner installs
- Bug-fix cycle on whatever the pilot surfaces

**Exit criterion:** pilot success criteria met. Tag v1.0.0.

### Phase H — Plugins Directory submission (Week 9)

- Submit to Moodle Plugins Directory under "Approved" status
- Reviewer feedback cycle (typically 1-3 rounds, 2-6 weeks calendar)
- Bug fixes for whatever reviewer flags
- Tag final release upon approval

**Exit criterion:** Plugin appears in the public Plugins Directory under "Approved" status, satisfying G4.

---

## 4. Architecture decisions to lock before coding starts

These are open questions that block work. Decide before Phase A starts.

### D1: Session token storage

Per §10.2, session token is HMAC-bound. **Decision needed:** is `session_token` stored in `mdl_fastpix_attempt` (current schema) or only validated on the fly?

**Recommendation:** store it. The schema already has the column. On-the-fly validation requires the AMD module to round-trip the token; storage means we can validate against the row directly. One less attack surface.

### D2: Player TTL and refresh strategy

Per §6.3, JWT TTL is 300s; AMD module refreshes 30s before expiry. **Decision needed:** refresh from where? Direct call to `local_fastpix` gateway? New `mod_fastpix` external function?

**Recommendation:** new `mod_fastpix\external\refresh_playback_token`. Reason: the refresh decision needs the activity context (capability still held? attempt still valid?). Pure gateway call would skip those checks.

### D3: Where the watch-tracker AMD module lives

Per §6.6, `record_view_progress` is registered as `mod_fastpix_record_view_progress`. **Decision needed:** the AMD module that calls it — does it live in `mod_fastpix/amd/src/watch_tracker.js` or in `local_fastpix`?

**Recommendation:** `mod_fastpix`. Reason: the AMD module is activity-aware (reads `data-activity-id`, `data-session-token` from the wrapper div). Putting it in `local_fastpix` creates a hidden dependency from `local_fastpix` on `mod_fastpix` activity surface — wrong direction.

### D4: Capability set

Three capabilities are required (`mod/fastpix:addinstance`, `:view`, `:viewallattempts`). **Decision needed:** any others? Specifically: `mod/fastpix:graderoverride` for teacher manual gradebook intervention?

**Recommendation:** add `mod/fastpix:graderoverride` as a teacher-level capability. Without it, fraud_count > 20 badges have no human escalation path. Default archetypes: `editingteacher` and `manager` only.

### D5: Cross-activity user attempt collision

Schema is `UNIQUE(user_id, activity_id)`. **Decision needed:** what happens if a teacher edits the activity to point at a different asset? The user's old `watched_seconds` carries over to the new video?

**Recommendation:** on activity asset change, soft-reset attempts: copy current `watched_seconds` to a `watched_seconds_archived` column (new column), reset `watched_seconds = 0`. Schema migration in upgrade.php for v1.0.

If that's too much for v1.0, alternative: forbid changing the asset on an activity that has any attempts. Cleaner, less code.

---

## 5. Test strategy (per design doc §12.5)

Per the doc, mandatory coverage:

| Surface | Test type | Coverage target |
|---------|-----------|-----------------|
| `record_view_progress` (watch_tracker_service) | PHPUnit, all 6 fraud checks | ≥ 90% |
| `custom_completion` rule | PHPUnit, threshold boundaries | ≥ 85% |
| `mod_form` validation | PHPUnit | ≥ 85% |
| `view.php` happy path | Behat | Required |
| Watch-% completion + gradebook | Behat | Required (happy + no-skip) |
| Backup/restore round-trip | Behat | Required |

Plus the implicit requirements:

- Every external function has its own PHPUnit test class
- Every fraud path produces a deterministic assertion (boundary tests for the 10s tolerance especially)
- Every webservice has an `ajax=true` integration smoke test

**Tools:**

- `moodle-plugin-ci` in CI for every PR
- Coverage gate: PR-level fail if any of the targets above regress
- Manual reconciliation per RC: 100-attempt sample vs FastPix Data API (G2)

---

## 6. Risks & mitigations

| # | Risk | Probability | Impact | Mitigation |
|---|------|-------------|--------|------------|
| R1 | DRM L3 screen-recording on student devices | High | Low (per design doc §11.3 T2: accepted threat in v1.0) | Document as known limitation; year-2 escalation = server-side burn-in |
| R2 | `local_fastpix` API contract changes mid-build | Medium | High (would block multiple phases) | Lock `local_fastpix` v0.2.0 frozen interface before Phase A; any change requires ADR |
| R3 | Watch-tracker callback storms during peak load | Medium | Medium | Pre-GA k6 load test per §7 — confirm 200 QPS sustained, 500 QPS burst handled; tune per results |
| R4 | Cross-FastPix-account restore confusion | Low | Medium | ADR-010 defines "Video unavailable" message; ship as documented limitation |
| R5 | Moodle 4.5 → 4.6+ API drift during pilot | Low | High | Test against Moodle 4.5 LTS only for v1.0; 4.6 support in v1.1 |
| R6 | Browser DRM compatibility regression | Medium | High | Behat matrix tests Chrome/Safari/Firefox/Edge per RC; Moodle Mobile WebView via ADR-007 |
| R7 | `moodle-plugin-ci` failures on Plugins Directory submission | Medium | Medium | Run `moodle-plugin-ci` on every PR from Phase A; Phase F includes a manual run against the canonical CI image before submission |
| R8 | Session token replay attack | Low | High | Token is HMAC-bound to `(user_id, activity_id, session_start_ts)`; cross-session replay impossible. Audit per Phase F. |

---

## 7. Cross-plugin contracts that must hold

`mod_fastpix` consumes specific surfaces of `local_fastpix`. These must be
treated as frozen interfaces — if they change, `mod_fastpix` breaks.

### Web services consumed

- `local_fastpix_create_upload_session` — used by mod_form upload tab
- `local_fastpix_create_url_pull_session` — used by mod_form URL tab
- `local_fastpix_get_upload_status` — used by view.php processing-state poller
- `local_fastpix_test_connection` — NOT consumed (admin-only)
- `local_fastpix_send_test_event` — NOT consumed (admin-only)

### Web services exposed (owned by mod_fastpix)

- `mod_fastpix_record_view_progress` — called by watch_tracker AMD every 10s
- `mod_fastpix_refresh_playback_token` — called by player AMD ~30s before JWT expiry

### PHP namespaces consumed

- `\local_fastpix\service\asset_service` — asset metadata lookups
- `\local_fastpix\service\playback_service` — DRM token minting (with refresh logic per D2)
- `\local_fastpix\service\upload_service` — upload session orchestration
- `\local_fastpix\service\jwt_signing_service` — DO NOT call directly; goes through playback_service
- `\local_fastpix\api\gateway` — NEVER call directly. Always go through a service.

### Database tables read but not written

- `mdl_local_fastpix_asset` — read for duration, status, drm_required, no_skip_required, has_captions
- `mdl_local_fastpix_track` — read for caption track availability

`mod_fastpix` must NEVER write to `local_fastpix` tables directly. All
mutation goes through `local_fastpix`'s service layer.

### Capabilities inherited

- `local/fastpix:configurecredentials` — admin-only; not consumed by mod_fastpix
- All `mod/fastpix:*` capabilities are owned and declared by `mod_fastpix`

---

## 8. Total effort summary

```
Phase A — Foundation                  1 week
Phase B — Activity edit form          1 week
Phase C — Student playback            1 week
Phase D — Watch tracking & completion 1 week
Phase E — Backup, restore, GDPR       1 week
Phase F — Polish, hardening, docs     1 week
                                    --------
Engineering effort                    6 weeks

Phase G — Pilot RC1                   2 weeks calendar (overlaps with bug fixes)
Phase H — Plugins Directory           2-6 weeks calendar (reviewer-paced)
                                    --------
End-to-end calendar                   ~10-12 weeks
```

Add 25% buffer for unknown unknowns: **call it 13-15 weeks calendar from kick-off to public release.**

---

## 9. Definition of done

`mod_fastpix` v1.0 ships when ALL of the following are true:

1. Plugin installs cleanly on a fresh Moodle 4.5 LTS install
2. `moodle-plugin-ci` passes on PHP 8.1 + 8.2 × MySQL 8 + PostgreSQL 13
3. PHPUnit coverage targets met per §5
4. All Behat tests pass
5. 100-attempt sample reconciliation ≥ 99.5% on all 3 design partner sites for 14 consecutive days
6. Setup time on clean Moodle 4.5: p50 ≤ 20 minutes (G3)
7. Zero P0/P1 bugs at end of pilot
8. README, STATUS, and capability docs are complete
9. Approved listing in Moodle Plugins Directory (G4)
10. v1.0.0 git tag pushed to public repo

Anything less than all 10 is a `1.0.0-rc` tag, not a GA.

---

## Appendix A — File tree at end of Phase F

```
mod/fastpix/
├── amd/
│   ├── build/
│   │   ├── upload_widget.min.js
│   │   ├── watch_tracker.min.js
│   │   └── processing_state_poller.min.js
│   └── src/
│       ├── upload_widget.js
│       ├── watch_tracker.js
│       └── processing_state_poller.js
├── backup/
│   └── moodle2/
│       ├── backup_fastpix_activity_task.class.php
│       ├── backup_fastpix_stepslib.php
│       ├── restore_fastpix_activity_task.class.php
│       └── restore_fastpix_stepslib.php
├── classes/
│   ├── completion/
│   │   └── custom_completion.php
│   ├── event/
│   │   ├── activity_viewed.php
│   │   └── watch_milestone.php
│   ├── external/
│   │   ├── record_view_progress.php
│   │   └── refresh_playback_token.php
│   ├── output/
│   │   └── view_renderer.php
│   ├── privacy/
│   │   └── provider.php
│   └── service/
│       ├── playback_service.php
│       ├── session_token_service.php
│       └── watch_tracker_service.php
├── db/
│   ├── access.php
│   ├── install.xml
│   ├── services.php
│   └── upgrade.php
├── lang/
│   └── en/
│       └── mod_fastpix.php
├── pix/
│   ├── icon.svg
│   └── monologo.svg
├── templates/
│   ├── view.mustache
│   ├── processing.mustache
│   └── error.mustache
├── tests/
│   ├── behat/
│   │   ├── add_activity.feature
│   │   ├── student_view.feature
│   │   ├── no_skip_enforcement.feature
│   │   └── completion_grade.feature
│   ├── lib_test.php
│   ├── mod_form_test.php
│   ├── record_view_progress_test.php
│   ├── custom_completion_test.php
│   └── backup_restore_test.php
├── lib.php
├── mod_form.php
├── README.md
├── STATUS.md
├── version.php
└── view.php
```

---

## Appendix B — What v1.1 will NOT add

- Mobile Moodle App native player (replace WebView per ADR-007 follow-up)
- Reconciler (ADR-003) — reads FastPix Data API every 5 min, fixes drift
- Per-user watermark (ADR-005 reopen if customer demand) — burn-in path
- Course-level media gallery
- Per-chapter completion tracking
- Drop-off curves inside Moodle gradebook view

---

**Document version:** 1.0
**Last updated:** 2026-05-07
**Authors:** Engineering team for FastPix × Moodle integration
**Source:** Design Doc v5.3 §6.1, §6.5, §10.3, §12.5, §14, ADR-001 through ADR-011
