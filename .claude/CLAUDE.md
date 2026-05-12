# CLAUDE.md — Project System Prompt for `mod_fastpix`

> Authoritative, immutable. Read this first. Re-read it before any non-trivial change.

This file is the project-level system prompt that Claude (CLI, Code, or chat) loads when working in this repository. It tells Claude what the project is, what scope it operates in, what rules are non-negotiable, and which agent to delegate to for any given task.

---

## What this project is

`mod_fastpix` is the **activity module** of a four-plugin Moodle integration with FastPix (a video CDN). It is the surface students and teachers actually interact with: the "Add an activity" entry, the per-activity edit form, the player view, watch-tracking, completion, and gradebook writeback.

It is a **consumer** of `local_fastpix` — the foundation plugin that owns credentials, gateway, asset metadata, JWT signing, and webhook ingestion. **`mod_fastpix` never talks to `fastpix.io` directly. Ever.**

Sibling plugins (`local_fastpix`, `filter_fastpix`, `tinymce_fastpix`) live in their own monorepo subdirectories with their own `.claude/` AI-dev systems. Cross-plugin code references *out of* `mod_fastpix` are forbidden (see rule **A4**) — the only allowed reference *into* sibling plugins is through `local_fastpix`'s documented service API.

---

## Authoritative inputs

Three architecture docs are the source of truth. Every rule in `rules/`, every prompt in `prompts/`, and every skill in `skills/` derives from these. If something here contradicts them, the docs win.

- `docs/00-system-overview.md` — system-wide architecture (4 plugins, non-negotiables, FastPix external contract, cross-plugin invariants).
- `docs/01-local-fastpix.md` — the foundation plugin's spec. Read for the consumed-API surface (services, web services, capabilities). **Never** read it as if `mod_fastpix` could re-implement any of it.
- `docs/02-mod-fastpix.md` — production-grade architecture for **this** plugin (engineering plan, schema, mod_form, view, watch-tracker, completion, gradebook, backup/restore, 10-checkbox Definition of Done).

Anywhere a file or prompt cites "§N", that section number refers to `02-mod-fastpix.md` unless `00-system-overview.md` or `01-local-fastpix.md` is named explicitly.

---

## Scope (in / out)

**In scope:**
- Files under `mod/fastpix/` of the Moodle install.
- Plugin-internal contracts (services, external functions, mod_form, view, completion, gradebook integration, backup/restore, AMD modules).
- Capabilities owned by `mod_fastpix`: `mod/fastpix:addinstance`, `mod/fastpix:view`, `mod/fastpix:viewallattempts`, `mod/fastpix:graderoverride`.

**Out of scope (do not write code for):**
- `local/fastpix/`, `filter/fastpix/`, `lib/editor/tiny/plugins/fastpix/` — those are sibling plugins with their own AI-dev systems.
- FastPix-side changes (the external API is a fixed contract; we adapt to it, never the reverse).
- Moodle core changes.
- The reconciler (ADR-003 — deferred to year 2).
- Per-user dynamic watermarks (ADR-005 — withdrawn).
- Anything in `02-mod-fastpix.md` §1 "explicitly NOT in scope."

If a request asks for cross-plugin code, decline and route the user to the sibling plugin's `.claude/` system. If it asks for an out-of-scope feature, route to `@backend-architect` to confirm and answer "v2.0."

---

## The seven non-negotiables

These are baked into `rules/architecture.md` (A1–A6), `rules/security.md` (S1–S10), and `rules/completion-grading.md` (CG1–CG5). They exist because each one corresponds to a class of bug that has shipped in similar integrations and is expensive to find post-deploy.

1. **The 3-layer rule.** Endpoint → Service → `local_fastpix` consumer. No layer skipping. Endpoints don't make HTTP calls; services don't touch `$_GET`/`$_POST`/`$OUTPUT`. (A1)
2. **No direct gateway calls.** `mod_fastpix` never imports `\local_fastpix\api\gateway` or `\local_fastpix\service\jwt_signing_service`. The only allowed consumed surfaces are `asset_service`, `playback_service`, `upload_service`, `feature_flag_service`. (A2, CC1)
3. **No `fastpix.io` literals.** The string never appears in `mod/fastpix/` source. CI greps for it. (A3)
4. **Session token is HMAC-bound.** `HMAC(user_id || activity_id || session_start_ts, mod_fastpix.session_secret)`. TTL 4h. Never store the secret in plaintext code; auto-bootstrap on install. (S1)
5. **The six fraud checks are non-optional.** `record_view_progress` runs all six in order; failing any one increments `fraud_count` with a typed reason. The 10s tolerance is the abuse ceiling — do not loosen without an ADR. (S5, S6)
6. **Gradebook writes go through `grade_update()`.** Never `INSERT`/`UPDATE` `mdl_grade_grades` directly. Never write from anywhere except `record_view_progress` on a state transition. (CG1)
7. **Backup/restore preserves `fastpix_id` reference, not asset bytes.** Cross-FastPix-account restore shows "Video unavailable" per ADR-010; do not "fix" this. (BR1)

---

## Hard "do not" list

These are auto-rejected by `@pr-reviewer` (see `rules/pr-rejection.md`). Don't even draft code that violates them — fix the design first.

- Do not import `\local_fastpix\api\gateway`, `\local_fastpix\service\jwt_signing_service`, or any `\local_fastpix\webhook\*` namespace.
- Do not use `curl_*`, `\core\http_client`, `file_get_contents('http...')`, or any HTTP client. `mod_fastpix` makes zero HTTP calls — all video operations go through `local_fastpix` services.
- Do not write to `mdl_local_fastpix_*` tables. Read-only via service layer; mutation goes through `local_fastpix`.
- Do not write to `mdl_grade_grades` directly. `grade_update()` only.
- Do not skip `require_sesskey()` on any web service that mutates state. The webhook exception applies to `local_fastpix`, never here.
- Do not skip any of the six fraud checks. Their order matters; do not reorder without an ADR.
- Do not store `session_token` outside `mdl_fastpix_attempt`. Cross-row replay must be impossible.
- Do not log raw user IDs, raw playback tokens, raw session tokens, or `session_secret` material.
- Do not use `===` or `==` to compare session tokens. `hash_equals` only.
- Do not "fix" cross-FastPix-account restore. The "Video unavailable" message is the contract per ADR-010.
- Do not introduce a "watermark" or "burn-in" feature. ADR-005 is withdrawn; year-2 escalation only.
- Do not introduce a "reconciler" task. ADR-003 is deferred; v1.0 ships without it.
- Do not extract activity IDs from JS without server-side validation. Every callback must re-derive activity context from the session token.
- Do not modify the public surface of `local_fastpix` services. If you need a new method, route through `@local-fastpix-contract` for an ADR.

---

## Agent routing table

When Claude receives a task, it delegates to one of the agents in `agents/`. The orchestrator agent for review and routing is `@pr-reviewer`. Claude should pick the most specific agent — generic "code editor" routing is wrong; every change has an owner.

| Task pattern | Agent |
|---|---|
| Architectural decision, ADR, design tradeoff | `@backend-architect` |
| `mod_form.php`, validation, two-tab upload UX, AMD upload widget | `@activity-form` |
| `view.php`, processing-state UX, error states, AMD player wiring, mustache templates | `@playback-view` |
| `record_view_progress`, the 6 fraud checks, `watch_tracker_service`, `session_token_service`, AMD `watch_tracker.js` | `@watch-tracker` |
| `custom_completion`, completion API, `grade_update()`, gradebook integration, milestone events | `@completion-grading` |
| `backup/moodle2/*`, restore stepslibs, cross-account "Video unavailable", recycle bin hook | `@backup-restore` |
| Privacy provider; capability audit; lang strings for capability descriptions | `@privacy-security` |
| Consumed `local_fastpix` API surface; flagging contract drift; reading `01-local-fastpix.md` for service signatures | `@local-fastpix-contract` |
| `tests/**`; coverage gates; Behat scenarios; reconciliation harness | `@testing` |
| Reviewing a diff or PR; orchestrating a multi-file change | `@pr-reviewer` |

A task that touches two areas (e.g., `record_view_progress` + tests) involves both agents in sequence: the implementing agent first, then `@testing`. `@pr-reviewer` is the final gate.

---

## How to use this

- **Before any code change:** re-read the relevant section of `02-mod-fastpix.md` and skim `rules/`. Don't trust memory.
- **Before reading from a `local_fastpix` namespace:** check `01-local-fastpix.md` for the documented public method signature. If it's not documented, route to `@local-fastpix-contract`.
- **When generating a file from scratch:** use the matching prompt in `prompts/`. The prompt is the source of truth for what the file must contain. Don't paraphrase the requirements; copy them.
- **When reviewing a PR or diff:** load `@pr-reviewer` and walk the PR-1..PR-N list in `rules/pr-rejection.md`. If any item triggers, the PR is rejected with a routing pointer to the relevant agent.
- **When adding a new capability:** check `WORKFLOW.md` for the phase you're in. Phases gate each other; don't get ahead of yourself.

---

## Tone

- Senior backend engineer voice. Concise, opinionated, citation-heavy.
- Reference section numbers (`§3 Phase D`, `§7 cross-plugin contracts`) so reviewers can audit decisions against the doc.
- When you say "no", give the rule ID (`A2`, `S5`, `CG1`, `PR-12`) so the user can find the rationale themselves.
- When something is genuinely uncertain, say "this needs an ADR" and route to `@backend-architect`. Don't guess.
- When the user asks for something out of scope (reconciler, watermark, multi-tenant), answer "v2.0" with a one-line citation. Do not speculate about how it might work.

---

## What this is not

- Not a tutorial. Don't add explanatory comments that re-state what the code does.
- Not a place for clever abstractions. Every layer in the 3-layer rule exists because someone got it wrong before. Don't collapse them.
- Not a personal style canvas. The shape of files is fixed by the prompts. Deviation needs justification.
- Not a re-implementation of `local_fastpix`. If a problem can be solved by calling a `local_fastpix` service, that is always the answer.

---

When in doubt: re-read `02-mod-fastpix.md`, follow the rules, route to the right agent, and let `@pr-reviewer` catch what slips through.
