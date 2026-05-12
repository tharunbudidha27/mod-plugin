# Skill 11 — Privacy Provider

**Owner agent:** `@privacy-security`.

**When to invoke:** Phase E.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase E.
- `.claude/rules/security.md` (S10).
- Moodle's `core_privacy\local\metadata\provider` and `core_privacy\local\request\plugin\provider` documentation.

## Outputs

- `mod/fastpix/classes/privacy/provider.php`
- Lang strings: `privacy:metadata:fastpix_attempt`, `privacy:metadata:fastpix_attempt:userid`, etc.

## Steps

### 1. provider class

```php
namespace mod_fastpix\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'fastpix_attempt',
            [
                'userid'           => 'privacy:metadata:fastpix_attempt:userid',
                'activity_id'      => 'privacy:metadata:fastpix_attempt:activity_id',
                'session_token'    => 'privacy:metadata:fastpix_attempt:session_token',
                'session_start_ts' => 'privacy:metadata:fastpix_attempt:session_start_ts',
                'last_callback_ts' => 'privacy:metadata:fastpix_attempt:last_callback_ts',
                'watched_seconds'  => 'privacy:metadata:fastpix_attempt:watched_seconds',
                'seek_count'       => 'privacy:metadata:fastpix_attempt:seek_count',
                'fraud_count'      => 'privacy:metadata:fastpix_attempt:fraud_count',
                'completion_state' => 'privacy:metadata:fastpix_attempt:completion_state',
            ],
            'privacy:metadata:fastpix_attempt'
        );
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {fastpix_attempt} a
                  JOIN {fastpix} f ON f.id = a.activity_id
                  JOIN {course_modules} cm ON cm.instance = f.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'fastpix'
                  JOIN {context} ctx ON ctx.instanceid = cm.id
                                    AND ctx.contextlevel = :modlevel
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT a.userid
                  FROM {fastpix_attempt} a
                  JOIN {fastpix} f ON f.id = a.activity_id
                  JOIN {course_modules} cm ON cm.instance = f.id
                                          AND cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('fastpix', $context->instanceid);
            $activity = $DB->get_record('fastpix', ['id' => $cm->instance]);
            $attempt = $DB->get_record('fastpix_attempt', [
                'userid' => $userid,
                'activity_id' => $activity->id,
            ]);
            if (!$attempt) continue;

            writer::with_context($context)->export_data([], (object)[
                'activity_name'    => format_string($activity->name),
                'watched_seconds'  => $attempt->watched_seconds,
                'completion_state' => $attempt->completion_state,
                'seek_count'       => $attempt->seek_count,
                'fraud_count'      => $attempt->fraud_count,
                'session_start'    => userdate($attempt->session_start_ts),
                'last_callback'    => $attempt->last_callback_ts ? userdate($attempt->last_callback_ts) : null,
            ]);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('fastpix', $context->instanceid);
        if (!$cm) return;
        $DB->delete_records('fastpix_attempt', ['activity_id' => $cm->instance]);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) continue;
            $cm = get_coursemodule_from_id('fastpix', $context->instanceid);
            if (!$cm) continue;
            $DB->delete_records('fastpix_attempt', [
                'userid' => $userid, 'activity_id' => $cm->instance,
            ]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) return;
        $cm = get_coursemodule_from_id('fastpix', $context->instanceid);
        if (!$cm) return;
        list($insql, $params) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['activity_id'] = $cm->instance;
        $DB->delete_records_select('fastpix_attempt',
            "userid $insql AND activity_id = :activity_id", $params);
    }
}
```

## Validation

- `tests/privacy_provider_test.php`:
  - `get_metadata` declares all 9 columns.
  - `get_contexts_for_userid` returns module contexts where user has attempts.
  - `export_user_data` produces human-readable output (activity name, not ID; userdate-formatted timestamps).
  - `delete_data_for_user` removes attempts; subsequent `get_state` returns INCOMPLETE.
  - `get_users_in_context` returns the right user list.
  - Round-trip: export → delete → re-view shows zero progress.
- Coverage target: ≥ 85%.
