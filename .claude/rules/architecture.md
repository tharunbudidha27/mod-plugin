# Architecture Rules (A1–A6)

These rules govern *where code goes*. Layering, dependency direction, hot-path constraints. Cited by `@backend-architect`, `@local-fastpix-contract`, and `@pr-reviewer`.

Rule format: `ID | Rule statement | Enforcement mechanism | Failure routing`.

---

## A1 — 3-layer separation is mandatory

**Rule.** Code lives in exactly one of three layers:

| Layer | Lives in | Does | Must NOT do |
|---|---|---|---|
| UI / endpoint | `*.php` at root, `classes/external/`, `templates/`, `amd/src/` | `require_login`, `require_capability`, `require_sesskey`, validate input, delegate to service, render | Contain business logic. Make HTTP calls. Issue session tokens. |
| Service | `classes/service/` | Business rules, fraud checks, completion logic, idempotent operations, returns plain data | Touch `$_GET` / `$_POST` / `$OUTPUT`. Make HTTP calls. |
| `local_fastpix` consumer | calls into `\local_fastpix\service\*` namespaces | Bridge to FastPix-related operations | Be called from anywhere except a service. |

**Enforcement.** PR review checklist; static check that any file under `classes/external/`, `classes/task/`, or root `*.php` containing `\local_fastpix\service\` is rejected (must go through a service in `classes/service/`).

**Failure routing.** `@backend-architect` for the design fix; the relevant specialist agent for the implementation fix.

---

## A2 — `mod_fastpix` makes ZERO HTTP calls

**Rule.** No `curl_*`, no `\core\http_client`, no `file_get_contents` against `http(s)://...`. Every operation that ultimately hits FastPix goes through a `local_fastpix` service. `mod_fastpix` is a pure consumer.

**Enforcement.** CI script `.claude/ci-checks/grep-no-curl.sh` — `grep -rE 'curl_(init|exec|setopt)|core\\\\http_client|http_client::|file_get_contents.*http' mod/fastpix/ --include=*.php` returns zero matches.

**Failure routing.** `@local-fastpix-contract` (the right answer is "use a service").

---

## A3 — No `fastpix.io` literals anywhere

**Rule.** The string `fastpix.io` and `api.fastpix` MUST NOT appear in `mod/fastpix/` source. URLs, endpoints, anything. If you need a URL, it's already wrapped in a `local_fastpix` service.

**Enforcement.** CI script `.claude/ci-checks/grep-no-fastpix-literals.sh` — `grep -rE 'fastpix\\.io|api\\.fastpix' mod/fastpix/ --exclude-dir=.claude --exclude-dir=tests` returns zero matches.

**Failure routing.** `@local-fastpix-contract`.

---

## A4 — No cross-plugin imports

**Rule.** `mod_fastpix` MUST NOT reference `filter_fastpix` or `tinymce_fastpix`. Not in `version.php` `requires` (other than `local_fastpix`), not in namespaces, not in capability strings, not in lang strings. The only allowed cross-plugin reference is the documented public surface of `\local_fastpix\service\*`.

Specifically forbidden:
- `\local_fastpix\api\gateway` — direct gateway access
- `\local_fastpix\service\jwt_signing_service` — direct signing access
- `\local_fastpix\webhook\*` — webhook internals
- `\local_fastpix\dto\*` — internal DTOs (consume the public DTO from the service return value)
- Any `filter_fastpix` or `tinymce_fastpix` namespace

**Enforcement.** CI script `.claude/ci-checks/grep-no-direct-gateway.sh` — `grep -rE 'local_fastpix\\\\api\\\\gateway|local_fastpix\\\\service\\\\jwt_signing|local_fastpix\\\\webhook\\\\|filter_fastpix|tinymce_fastpix' mod/fastpix/ --include=*.php --exclude-dir=tests` returns zero matches. (Test fixtures may simulate these.)

**Failure routing.** `@local-fastpix-contract` for the consumed-API question; `@backend-architect` if the right answer involves a new `local_fastpix` service method (separate ADR + repo PR).

---

## A5 — No direct writes to `mdl_local_fastpix_*` tables

**Rule.** `mod_fastpix` may READ from `mdl_local_fastpix_asset` and `mdl_local_fastpix_track` (via `asset_service`), but it MUST NOT INSERT, UPDATE, or DELETE those tables. All mutation goes through `local_fastpix`'s service layer.

**Enforcement.** CI script `.claude/ci-checks/grep-no-direct-table-write.sh` — `grep -rE '\\\$DB->(insert_record|update_record|delete_records).*local_fastpix' mod/fastpix/` returns zero matches.

**Failure routing.** `@asset-service` (in `local_fastpix`'s plugin) is the eventual fix; `@local-fastpix-contract` to route the request.

---

## A6 — Services contain all business logic

**Rule.** No business logic in endpoints (`classes/external/*`, root `*.php`, `lib.php` callbacks beyond Moodle-required ones). Endpoints do the auth dance and delegate. Tasks delegate to services. The same service is callable from a web endpoint, a CLI script, a scheduled task, and an adhoc task — if you find yourself copying logic between endpoints, you skipped the service layer.

Specifically: the six fraud checks live in `\mod_fastpix\service\watch_tracker_service`, NOT in `\mod_fastpix\external\record_view_progress`. The external function does sesskey + capability + parameter validation, then delegates.

**Enforcement.** PR review.

**Failure routing.** Offending diffs routed to `@backend-architect` for redesign.
