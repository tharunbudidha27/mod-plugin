# `.claude/` — AI-assisted development system for `mod_fastpix`

This directory is a self-contained AI-dev system for the `mod_fastpix` Moodle plugin. It ships **with** the plugin source in your monorepo, but it is **not** part of the Moodle plugin payload — exclude it from `make-zip`, the Moodle Plugins Directory upload, and any CI step that doesn't need it.

It encodes the architecture decisions in `00-system-overview.md`, `01-local-fastpix.md` (the consumed-API surface), and `02-mod-fastpix.md` (the engineering plan) as agents, skills, prompts, and rules that Claude can load and apply consistently across a 6-week build.

---

## Quick start

1. This `.claude/` folder lives at the root of `mod/fastpix/` in your Moodle monorepo. The three architecture docs are already in `.claude/docs/`.
2. Open the repo in VS Code with the Claude extension, or run `claude` from the CLI in this directory.
3. Claude auto-loads `CLAUDE.md` (the project system prompt). Verify with: "Which agent owns the six fraud checks?" — expected answer: `@watch-tracker`.
4. Pick a phase from `WORKFLOW.md` and start. Each phase tells you which agent runs, which skill it invokes, and what the validation gate is.

---

## Layout

```
.claude/
├── CLAUDE.md                   # Project system prompt — Claude loads this first
├── README.md                   # You are here
├── WORKFLOW.md                 # 6-phase execution plan (Week 1 → GA)
├── docs/                       # Authoritative architecture inputs
│   ├── 00-system-overview.md   # System-wide (4 plugins, FastPix contract)
│   ├── 01-local-fastpix.md     # Consumed-API surface (read-only reference)
│   └── 02-mod-fastpix.md       # This plugin's engineering plan
├── agents/                     # 10 agents (9 specialists + 1 orchestrator)
│   ├── backend-architect.md
│   ├── activity-form.md
│   ├── playback-view.md
│   ├── watch-tracker.md
│   ├── completion-grading.md
│   ├── backup-restore.md
│   ├── privacy-security.md
│   ├── local-fastpix-contract.md
│   ├── testing.md
│   └── pr-reviewer.md          # orchestrator + reject-list enforcer
├── rules/                      # Hard rules cited by agents and PR review
│   ├── architecture.md         # A1–A6
│   ├── moodle-mod.md           # M1–M10 (mod-plugin-specific Moodle conventions)
│   ├── security.md             # S1–S10
│   ├── completion-grading.md   # CG1–CG5
│   ├── consumer-contract.md    # CC1–CC8 (how to consume local_fastpix)
│   └── pr-rejection.md         # PR-1..PR-N auto-reject conditions
├── skills/                     # 13 atomic build instructions
│   ├── 01-skeleton.md
│   ├── 02-schema.md
│   ├── 03-mod-form.md
│   ├── 04-view-and-processing.md
│   ├── 05-watch-tracker-amd.md
│   ├── 06-record-view-progress.md
│   ├── 07-session-token.md
│   ├── 08-custom-completion.md
│   ├── 09-gradebook-integration.md
│   ├── 10-backup-restore.md
│   ├── 11-privacy-provider.md
│   ├── 12-phpunit-tests.md
│   └── 13-behat-scenarios.md
├── prompts/                    # Self-contained generation prompts (1 per skill)
└── ci-checks/                  # Executable bash scripts run by @pr-reviewer
    ├── grep-no-direct-gateway.sh
    ├── grep-no-direct-table-write.sh
    ├── grep-no-grade-grades-write.sh
    ├── grep-no-curl.sh
    └── grep-session-token-on-progress.sh
```

---

## How agents, skills, prompts, rules, and docs relate

```
docs/        ← source of truth (architecture). Read-only for agents.
   │
   ▼
rules/       ← extracts hard rules from docs as enforcement IDs (A1, S5, CG1...)
   │
   ▼
agents/      ← 9 specialists, each with ownership boundary + guardrails
   │
   ▼
skills/      ← atomic build instructions (inputs, steps, outputs)
   │
   ▼
prompts/     ← self-contained text blocks Claude executes to produce a file
   │
   ▼
ci-checks/   ← greps that mechanically enforce the most important rules
```

When work happens:
1. User asks for a thing.
2. `CLAUDE.md` routes to the right agent.
3. Agent loads its frontmatter + the cited rules + the cited skill.
4. Agent invokes the prompt (or executes the skill steps).
5. `@pr-reviewer` runs `ci-checks/` + walks `rules/pr-rejection.md`.
6. PR merges or gets rejected with a rule ID.

---

## Excluded from production

This entire `.claude/` directory MUST be excluded from any production deployment:

- Add to `.gitignore` for plugin-payload builds (or use `make-zip` exclusion list).
- Do NOT upload to the Moodle Plugins Directory.
- Do NOT include in any tar/zip distributed to schools or hosting providers.

The directory is part of the development monorepo only.

---

## What lives in `mod_fastpix` vs sibling plugins

This is the most common confusion. Every line in `mod_fastpix` either:

- Renders something a teacher or student sees (`mod_form.php`, `view.php`, templates, AMD)
- Owns activity-scoped data (`mdl_fastpix`, `mdl_fastpix_attempt`)
- Implements activity-scoped business rules (the 6 fraud checks, completion threshold, gradebook write, backup/restore)
- Owns activity-scoped capabilities (`mod/fastpix:*`)

If you're tempted to write code that:
- Calls FastPix → wrong plugin. Belongs in `local_fastpix`.
- Validates a webhook → wrong plugin. Belongs in `local_fastpix`.
- Stores credentials → wrong plugin. Belongs in `local_fastpix`.
- Renders shortcodes inside rich text → wrong plugin. Belongs in `filter_fastpix`.
- Adds an editor button → wrong plugin. Belongs in `tinymce_fastpix`.

When in doubt, ask `@local-fastpix-contract`.

---

## Versioning of this directory

Treat `.claude/` as part of the plugin source code: version-controlled, PR-reviewed, evolves with the plugin. When `02-mod-fastpix.md` changes, the agents and rules that cite it must be updated in the same PR. Drift between docs and rules is a P1 bug.

---

## When this system is wrong

The agents push back when a request contradicts the rules. **The agents are not always right.** When a documented rule conflicts with reality (e.g., FastPix changes their webhook contract, or a Moodle 4.6 API forces a different shape), update `02-mod-fastpix.md` first, then propagate to rules and agents. Do not work around the agents by pasting raw code — that's how systems decay.

If you find yourself fighting an agent over a real change, route to `@backend-architect` for an ADR.
