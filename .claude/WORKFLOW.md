# WORKFLOW — mod_fastpix Build Plan

This is the 6-phase execution plan that drives the plugin from empty repo to GA in roughly six weeks of engineering effort plus pilot. Each phase names the agents that run, the skills they invoke, the artifacts they emit, and a hard validation checklist that **gates** entry into the next phase. The phase-to-week mapping matches §3 of `docs/02-mod-fastpix.md`.

A phase is not "done" until every checkbox is ticked. If a checkbox can't be ticked, the agent that owns the failing concern is the one to escalate to — see `agents/` for ownership.

**Hard precondition for Phase A:** `local_fastpix` v0.2.0+ must be installed and GA. Verify with: `php admin/cli/cfg.php --component=local_fastpix --name=version` — must return a release tag, not a dev tag.

---

## Phase A — Foundation (Week 1)

**Goal:** Plugin installs cleanly with empty schema, capabilities, and feature flags. An empty `mod_fastpix` activity can be added to a course and saved.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@backend-architect` | — | Sign-off on D1–D5 architecture decisions from §4 |
| `@local-fastpix-contract` | — | Verifies `local_fastpix` version + frozen interface |
| `@privacy-security` | Skill 01, Skill 02 | `version.php`, `lib.php` (feature constants + `_supports`), `lang/en/mod_fastpix.php`, `db/install.xml`, `db/access.php`, `db/upgrade.php` (skeleton), `db/services.php` (skeleton), `pix/icon.svg`, `pix/monologo.svg` |
| `@activity-form` | — | Empty `mod_form.php` with title + intro only |
| `@playback-view` | — | Empty `view.php` with placeholder |
| `@testing` | Skill 12 | `tests/lib_test.php` for `_supports()` and feature flags |

**Validation checklist (gate to Phase B):**

- [ ] `moodle-plugin-ci install` passes on PHP 8.1/8.2 × Moodle 4.5 × MySQL/Postgres.
- [ ] `mdl_fastpix` and `mdl_fastpix_attempt` tables created with all indexes (`UNIQUE(user_id, activity_id)`, `(activity_id, completion_state)`).
- [ ] All four capabilities registered: `mod/fastpix:addinstance`, `mod/fastpix:view`, `mod/fastpix:viewallattempts`, `mod/fastpix:graderoverride`.
- [ ] Activity appears under "Add an activity" → "Assessment" section (`MOD_PURPOSE_ASSESSMENT`).
- [ ] Teacher can add a `mod_fastpix` activity to a test course, save it, see it on the course page, click it, get the placeholder view.
- [ ] `lib.php` `mod_fastpix_supports()` returns true for: `FEATURE_GRADE_HAS_GRADE`, `FEATURE_COMPLETION_HAS_RULES`, `FEATURE_BACKUP_MOODLE2`, `FEATURE_MOD_PURPOSE`.
- [ ] Plugin uninstalls cleanly with zero orphan tables (`mdl_fastpix*` count = 0 after uninstall).
- [ ] `tests/lib_test.php` 85%+ coverage on `_supports()`.

---

## Phase B — Activity edit form (Week 2)

**Goal:** Teacher can configure an activity by uploading a video or pasting a URL. Both flows persist correctly; pre-save validation catches malformed inputs.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@activity-form` | Skill 03 | Full `mod_form.php` with three fieldsets, two-tab video source, playback options, completion threshold, grade settings |
| `@local-fastpix-contract` | — | Confirms `local_fastpix_create_upload_session` and `local_fastpix_create_url_pull_session` signatures match consumed-API contract |
| `@activity-form` | — | `amd/src/upload_widget.js`, `templates/upload_widget.mustache` |
| `@privacy-security` | — | Lang strings for every form field, every error message, every help icon |
| `@testing` | Skill 12 | `tests/mod_form_test.php` (validation rules, both tabs, threshold edge cases) |
| `@testing` | Skill 13 | `tests/behat/add_activity.feature` |

**Validation checklist (gate to Phase C):**

- [ ] Teacher can upload a video via direct chunked upload; progress bar updates; activity row stores `upload_session_id`; `fastpix_id` is NULL until webhook arrives.
- [ ] Teacher can paste a URL; `local_fastpix_create_url_pull_session` is called; activity row created.
- [ ] Both-empty form rejects with a clear error.
- [ ] Malformed URL rejects (delegated to `local_fastpix` SSRF guard).
- [ ] Completion threshold outside (0, 100] rejects.
- [ ] Edit form on existing activity allows asset swap; old asset is soft-deleted via `local_fastpix` privacy path.
- [ ] No literal `fastpix.io` or `curl_*` anywhere in `mod_fastpix/` source (CI grep).
- [ ] No import of `\local_fastpix\api\gateway` anywhere (CI grep).
- [ ] `mod_form_test.php` 85%+ coverage.
- [ ] Behat: `add_activity.feature` happy path passes.

---

## Phase C — Student playback (Week 3)

**Goal:** Student clicks the activity, sees the player, can watch. No tracking yet. Processing-state UX works for in-flight assets.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@playback-view` | Skill 04 | Full `view.php`, `templates/view.mustache`, `templates/processing.mustache`, `templates/error.mustache`, `classes/output/view_renderer.php`, `amd/src/processing_state_poller.js` |
| `@watch-tracker` | Skill 07 | `classes/service/session_token_service.php` — issue + verify with HMAC |
| `@playback-view` | — | `classes/service/playback_service.php` — wraps `\local_fastpix\service\playback_service::resolve` and adds activity context |
| `@playback-view` | — | `classes/external/refresh_playback_token.php` (D2 decision: token refresh requires activity context, not pure gateway call) |
| `@privacy-security` | — | `\mod_fastpix\event\activity_viewed` event class |
| `@testing` | Skill 12 | `tests/session_token_service_test.php`, `tests/playback_service_test.php` |
| `@testing` | Skill 13 | `tests/behat/student_view.feature` |

**Validation checklist (gate to Phase D):**

- [ ] Student logs in, opens activity, sees video player, clicks play, video plays.
- [ ] Asset with `status !== 'ready'` shows the processing-state message; AMD poller calls `local_fastpix_get_upload_status` every 30s; transitions to player when ready.
- [ ] Asset not found shows "Video unavailable" (per ADR-010).
- [ ] DRM required + unsupported client shows fallback message.
- [ ] Session token issued on view; persisted in `mdl_fastpix_attempt.session_token`; HMAC verified with `hash_equals`.
- [ ] Token refresh endpoint requires capability + active attempt; returns new JWT before expiry.
- [ ] `\mod_fastpix\event\activity_viewed` fires on every view.
- [ ] `playback_service` 85%+ coverage; `session_token_service` 90%+ coverage.
- [ ] Behat: `student_view.feature` happy path passes on Chrome.

---

## Phase D — Watch tracking & completion (Week 4)

**Goal:** The whole gradebook story. Callbacks every 10s, all six fraud checks, completion rule, gradebook write, milestone events.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@watch-tracker` | Skill 05 | `amd/src/watch_tracker.js` — timeupdate + seeked listeners, 10s callback, retry logic |
| `@watch-tracker` | Skill 06 | `classes/external/record_view_progress.php`, `classes/service/watch_tracker_service.php` — the 6 fraud checks verbatim from §10.3 |
| `@completion-grading` | Skill 08 | `classes/completion/custom_completion.php` — single rule `completionwatchedpercent` |
| `@completion-grading` | Skill 09 | `lib.php` additions: `mod_fastpix_grade_item_update`, `mod_fastpix_update_grades`; `grade_update()` integration in `watch_tracker_service` |
| `@privacy-security` | — | `\mod_fastpix\event\watch_milestone` event class — fires at 25/50/75/100% |
| `@testing` | Skill 12 | `tests/record_view_progress_test.php` — boundary tests for all 6 fraud paths |
| `@testing` | Skill 12 | `tests/custom_completion_test.php` — threshold edge cases |
| `@testing` | Skill 13 | `tests/behat/completion_grade.feature`, `tests/behat/no_skip_enforcement.feature` |

**Validation checklist (gate to Phase E):**

- [ ] **Fraud check 1** — request with `watched_seconds > asset.duration` increments `fraud_count` with reason `exceeds_duration`; `watched_seconds` is NOT updated.
- [ ] **Fraud check 2** — request with `watched_seconds > (now - session_start_ts) + 10` rejected as `exceeds_wall_clock`.
- [ ] **Fraud check 3** — request with `watched_seconds < current_watched_seconds` rejected as `regression`.
- [ ] **Fraud check 4** — request with `(watched_seconds - prev_watched_seconds) > (elapsed_wall_clock + 10)` rejected as `implausible_gain`.
- [ ] **Fraud check 5** — request from a user who lost `mod/fastpix:view` mid-session rejected as `capability_lost`.
- [ ] **Fraud check 6** — on `no_skip_required=1` asset, any `client_seek_count > stored_seek_count` increments fraud as `seek_on_noskip`.
- [ ] All six are deterministic boundary tests in `record_view_progress_test.php`.
- [ ] On legitimate progress, `mdl_fastpix_attempt.watched_seconds` is updated by single UPDATE-by-PK.
- [ ] When `watched_seconds / asset.duration >= threshold`, `custom_completion::update_state` flips the activity to `COMPLETION_COMPLETE` AND `grade_update()` is called.
- [ ] Watch milestone events fire exactly once per threshold (idempotent).
- [ ] `mdl_fastpix_attempt.fraud_count > 20` surfaces as a row badge in gradebook view (capability-gated by `mod/fastpix:viewallattempts`).
- [ ] Coverage: `watch_tracker_service` 90%+, `custom_completion` 85%+, `record_view_progress` external function 90%+.
- [ ] Behat: `completion_grade.feature` and `no_skip_enforcement.feature` pass.
- [ ] No `mdl_grade_grades` direct write anywhere (CI grep).
- [ ] No fraud-check skip / reorder vs §10.3 (manual code review).

---

## Phase E — Backup, restore, GDPR (Week 5)

**Goal:** Activity survives course duplication and GDPR data subject requests. Cross-FastPix-account restore shows the documented "Video unavailable" message gracefully.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@backup-restore` | Skill 10 | `backup/moodle2/backup_fastpix_activity_task.class.php`, `backup/moodle2/backup_fastpix_stepslib.php`, `backup/moodle2/restore_fastpix_activity_task.class.php`, `backup/moodle2/restore_fastpix_stepslib.php` |
| `@privacy-security` | Skill 11 | `classes/privacy/provider.php` |
| `@backup-restore` | — | `lib.php` addition: `mod_fastpix_pre_course_module_delete` hook for recycle bin |
| `@testing` | Skill 12 | `tests/backup_restore_test.php` — round-trip with mock asset (same-account + cross-account scenarios) |
| `@testing` | Skill 13 | Behat assertions added to `add_activity.feature` for backup/restore round-trip |

**Validation checklist (gate to Phase F):**

- [ ] Backup of a course with a `mod_fastpix` activity captures: activity row, all `mdl_fastpix_attempt` rows, the `fastpix_id` reference. Does NOT capture asset bytes.
- [ ] Restore in same FastPix account: video plays after restore.
- [ ] Restore in different FastPix account: shows "Video unavailable" — does NOT throw, does NOT corrupt restore.
- [ ] Privacy provider declares all `mdl_fastpix_attempt` columns containing PII.
- [ ] `delete_data_for_user` deletes attempts for a user; subsequent view shows zero progress.
- [ ] `export_user_data` includes activity history (activity name, watched_seconds, completion_state, timestamps).
- [ ] `get_users_in_context` enumerates all users with attempts in a course context.
- [ ] Recycle bin: deleting an activity calls `local_fastpix` soft-delete on the asset (when no other activity references it).
- [ ] `tests/backup_restore_test.php` 85%+ coverage.

---

## Phase F — Polish, hardening, documentation (Week 6)

**Goal:** Production-readiness. All tests green, `moodle-plugin-ci` clean, manual install timed, README + STATUS docs complete. Tag v1.0.0-rc1.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@privacy-security` | — | Full lang file review; no `[[lang_key]]` anywhere |
| `@playback-view` | — | Mustache template accessibility audit (ARIA labels, keyboard nav, focus rings) |
| `@backend-architect` | — | `README.md`, `STATUS.md` |
| `@testing` | — | Full `moodle-plugin-ci` run (canonical CI image); coverage report |
| `@pr-reviewer` | — | Final pass over all `ci-checks/`; G1/G2/G3 measurement on a clean Moodle 4.5 install |

**Validation checklist (gate to Phase G):**

- [ ] `moodle-plugin-ci` passes cleanly: phpcs, phpmd, phplint, mustache-lint, savepoints, behat, phpunit.
- [ ] Coverage targets met per §5: `record_view_progress` ≥ 90%, `custom_completion` ≥ 85%, `mod_form` validation ≥ 85%.
- [ ] All 4 Behat features pass on Chrome.
- [ ] No literal `fastpix.io` in any source file outside `.claude/docs/`.
- [ ] No `\core\http_client` or `curl_*` anywhere in `mod/fastpix/`.
- [ ] No write to `mdl_grade_grades` direct.
- [ ] No write to `mdl_local_fastpix_*` direct.
- [ ] All capability strings registered match those in `db/access.php`.
- [ ] Lang file complete; `string_manager`'s missing-string check returns zero.
- [ ] Mustache templates pass accessibility audit (manual: tab order, screen reader, focus rings on player wrapper).
- [ ] Setup time: clean Moodle 4.5 → first video playing measured at p50 ≤ 20 minutes (G3).
- [ ] README documents: install, configuration, capability matrix, known limitations (L3 screen recording, cross-FastPix-account restore).
- [ ] STATUS.md mirrors `local_fastpix` format and lists all 10 DoD items with current state.
- [ ] Tag `v1.0.0-rc1` on the public repo.

---

## Phase G — Pilot RC1 (Weeks 7–8)

**Goal:** Ship to design partners. Watch what breaks. Hit the G2 reconciliation target on real traffic.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@backend-architect` | — | Pilot deployment plan, partner onboarding doc |
| `@testing` | — | Reconciliation harness — 100-attempt sample comparing `mdl_fastpix_attempt.watched_seconds` against FastPix Data API per RC |
| `@watch-tracker` | — | Bug fixes for any P0/P1 surfaced in pilot |
| `@playback-view` | — | Browser DRM compatibility patches for any partner-specific issue |

**Validation checklist (gate to Phase H):**

- [ ] Deployed to 3 design partner Moodle sites (per §14.1).
- [ ] Zero P0/P1 bugs at end of pilot.
- [ ] 100-attempt sample reconciliation ≥ 99.5% on all 3 sites for 14 consecutive days.
- [ ] Setup time p50 ≤ 20 min confirmed across all partner installs.
- [ ] No `fraud_count > 20` patterns showing on legitimate users (validates the 6 checks aren't over-firing).
- [ ] Tag `v1.0.0` after success criteria met.

---

## Phase H — Plugins Directory submission (Week 9+)

**Goal:** Get listed under "Approved" status in the Moodle Plugins Directory.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@backend-architect` | — | Submission package, `make-zip` excluding `.claude/`, `tests/`, dev artifacts |
| `@privacy-security` | — | Privacy provider audit per Plugins Directory checklist |
| `@pr-reviewer` | — | Final pass; reviewer feedback cycle |

**Validation checklist (Definition of Done):**

- [ ] Submitted to Moodle Plugins Directory.
- [ ] Reviewer feedback addressed (typically 1-3 rounds, 2-6 weeks calendar).
- [ ] Plugin appears under "Approved" status (G4).
- [ ] All 10 DoD items in `02-mod-fastpix.md` §9 are ✓.

---

## How phases gate each other

```
Phase A ──[install + skeleton]──▶ Phase B
Phase B ──[teacher upload works]──▶ Phase C
Phase C ──[student playback works]──▶ Phase D
Phase D ──[completion + gradebook]──▶ Phase E
Phase E ──[backup + GDPR]──▶ Phase F
Phase F ──[v1.0.0-rc1 tagged]──▶ Phase G
Phase G ──[v1.0.0 tagged]──▶ Phase H
Phase H ──[Plugins Directory approved]──▶ DONE
```

Skipping a phase is forbidden. If you find yourself "writing watch tracker code in Phase B," stop and finish Phase B first. The phases exist because each one's exit criterion catches a class of bug that's expensive to find late.

---

## When a phase blocks

If a checkbox can't be ticked, the agent that owns the failing concern is the one to escalate to. Routing:

- Schema/install issues → `@privacy-security` (Phase A) or `@backend-architect`
- Form validation failures → `@activity-form`
- Playback / token / processing UX → `@playback-view`
- Fraud check / session token / callback flake → `@watch-tracker`
- Completion / gradebook drift → `@completion-grading`
- Backup/restore corruption → `@backup-restore`
- Privacy / capability / lang gaps → `@privacy-security`
- `local_fastpix` interface drift → `@local-fastpix-contract`
- Anything with no clear owner → `@backend-architect`
