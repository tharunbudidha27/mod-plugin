# Skill 02 — Database Schema (`mdl_fastpix`, `mdl_fastpix_attempt`)

**Owner agent:** `@privacy-security` (declares PII columns) + `@backend-architect` (sign-off).

**When to invoke:** Phase A, step 2.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase A and §6.5 (schema spec).
- Moodle XMLDB editor conventions.

## Outputs

- `mod/fastpix/db/install.xml`
- `mod/fastpix/db/upgrade.php` (skeleton for v1.0; empty body)
- `mod/fastpix/db/access.php` (capability declarations)
- `mod/fastpix/db/services.php` (skeleton; web services added in Phases C/D)

## Steps

### 1. install.xml — `mdl_fastpix` (activity instances)

Required fields:
| Field | Type | Notes |
|---|---|---|
| `id` | int(10) | PK, sequence |
| `course` | int(10) | NOTNULL, FK → `course` |
| `name` | char(255) | NOTNULL |
| `intro` | text | nullable |
| `introformat` | int(2) | NOTNULL DEFAULT 0 |
| `fastpix_asset_id` | int(10) | nullable until webhook arrives, then FK → `local_fastpix_asset` |
| `upload_session_id` | int(10) | nullable, FK → `local_fastpix_upload_session` (for in-flight uploads) |
| `completion_watch_percent` | int(3) | NOTNULL DEFAULT 90 |
| `no_skip_required` | int(1) | NOTNULL DEFAULT 0 (boolean per-activity override) |
| `default_show_captions` | int(1) | NOTNULL DEFAULT 0 |
| `grademax` | number(10,5) | NOTNULL DEFAULT 100 |
| `timecreated` | int(10) | NOTNULL |
| `timemodified` | int(10) | NOTNULL |

Keys: PK on id; FK on course → course.id.
Indexes: none beyond keys (small table).

### 2. install.xml — `mdl_fastpix_attempt` (per-user watch attempts)

Required fields:
| Field | Type | Notes |
|---|---|---|
| `id` | int(10) | PK, sequence |
| `userid` | int(10) | NOTNULL, FK → `user` |
| `activity_id` | int(10) | NOTNULL, FK → `fastpix.id` |
| `asset_id` | int(10) | NOTNULL, FK → `local_fastpix_asset` (snapshot at session start) |
| `session_token` | char(64) | NOTNULL (S1 — HMAC hex) |
| `session_start_ts` | int(10) | NOTNULL |
| `last_callback_ts` | int(10) | nullable |
| `watched_seconds` | int(10) | NOTNULL DEFAULT 0 |
| `seek_count` | int(10) | NOTNULL DEFAULT 0 |
| `fraud_count` | int(10) | NOTNULL DEFAULT 0 |
| `last_fraud_reason` | char(32) | nullable |
| `completion_state` | char(16) | NOTNULL DEFAULT 'in_progress' |
| `milestone_25_at` | int(10) | nullable |
| `milestone_50_at` | int(10) | nullable |
| `milestone_75_at` | int(10) | nullable |
| `milestone_100_at` | int(10) | nullable |

Keys: PK on id; FK on userid → user.id; FK on activity_id → fastpix.id.
Indexes:
- `UNIQUE(userid, activity_id)` — one attempt row per user per activity (per §6.5).
- `(activity_id, completion_state)` — for gradebook queries.

### 3. db/upgrade.php

```php
<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_mod_fastpix_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // No upgrades for v1.0; install.xml is the source of truth.
    // Add migrations here in v1.1+, with savepoint per Moodle convention.

    return true;
}
```

### 4. db/access.php

Per M3, exactly four capabilities:

```php
$capabilities = [
    'mod/fastpix:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],
    'mod/fastpix:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['student' => CAP_ALLOW, 'teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'mod/fastpix:viewallattempts' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'mod/fastpix:graderoverride' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
];
```

### 5. db/services.php (skeleton — fleshed out in Phases C/D)

```php
$functions = [];      // populated in Phase C (refresh_playback_token) and Phase D (record_view_progress)
$services = [];       // mod_fastpix doesn't define services; uses Moodle's mobile + REST services
```

## Validation

- Tables created on `php admin/cli/install_database.php` with all indexes.
- `mdl_fastpix.fastpix_asset_id` is correctly nullable.
- `mdl_fastpix_attempt` UNIQUE(userid, activity_id) enforced — INSERT of duplicate fails.
- `db/access.php` declares exactly four capabilities; `tests/lib_test.php` asserts.
- Plugin uninstalls cleanly; `mdl_fastpix*` tables count = 0 after uninstall.
