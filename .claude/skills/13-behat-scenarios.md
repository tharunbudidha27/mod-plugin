# Skill 13 — Behat Scenarios

**Owner agent:** `@testing`.

**When to invoke:** Phase B (add_activity), Phase C (student_view), Phase D (completion_grade + no_skip).

---

## Inputs

- `docs/02-mod-fastpix.md` §5 (test strategy).
- Moodle's `behat_base` and `behat_data_generators` documentation.

## Outputs

- `mod/fastpix/tests/behat/add_activity.feature`
- `mod/fastpix/tests/behat/student_view.feature`
- `mod/fastpix/tests/behat/completion_grade.feature`
- `mod/fastpix/tests/behat/no_skip_enforcement.feature`

## Steps

### 1. add_activity.feature (Phase B + Phase E backup/restore)

```gherkin
@mod @mod_fastpix
Feature: Teacher adds a FastPix video activity
  In order to share videos with students
  As a teacher
  I need to add a FastPix activity and configure playback options

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the FastPix sandbox is mocked
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on

  Scenario: Teacher adds an activity via URL pull
    When I add a "FastPix Video" to section "1"
    And I set the following fields:
      | Activity name | Lecture 3 — Quantum Mechanics |
      | Source        | Paste URL                      |
      | URL           | https://example.com/lecture3.mp4 |
    And I press "Save and display"
    Then I should see "Lecture 3 — Quantum Mechanics"
    And I should see "This video is still processing"

  Scenario: Teacher cannot save with empty source
    When I add a "FastPix Video" to section "1"
    And I set the following fields:
      | Activity name | Empty                          |
    And I press "Save and display"
    Then I should see "You must upload a video or paste a URL"

  Scenario: Backup and restore preserves activity reference
    Given the following "activities" exist:
      | activity | name      | course | section | fastpix_asset_id |
      | fastpix  | Lecture 4 | C1     | 1       | <existing_id>    |
    When I backup "Course 1" course using this options:
      | Initial | Include enrolled users | 1 |
    And I restore the most recent backup into "Course 2"
    Then I should see "Lecture 4" in "Course 2"
```

### 2. student_view.feature (Phase C)

```gherkin
@mod @mod_fastpix
Feature: Student watches a FastPix video
  As a student enrolled in a course with a FastPix activity
  I should be able to open the activity and play the video

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname |
      | student1 | Student   |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the FastPix sandbox is mocked with a ready asset
    And the following "activities" exist:
      | activity | name      | course | section | fastpix_asset_id |
      | fastpix  | Lecture 1 | C1     | 1       | 1                |
    And I log in as "student1"
    And I am on "Course 1" course homepage

  Scenario: Student opens a ready video
    When I follow "Lecture 1"
    Then I should see "Lecture 1"
    And the "fastpix-player" element should be visible

  Scenario: Student opens a processing video
    Given the asset for "Lecture 1" is in "preparing" state
    When I follow "Lecture 1"
    Then I should see "This video is still processing"
    And I should NOT see the "fastpix-player" element

  Scenario: Student opens a deleted video
    Given the asset for "Lecture 1" is "deleted"
    When I follow "Lecture 1"
    Then I should see "Video unavailable"
```

### 3. completion_grade.feature (Phase D)

```gherkin
@mod @mod_fastpix @completion
Feature: Watch completion writes to gradebook
  When a student watches enough of a video, completion and grade are recorded

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname |
      | student1 | Student   |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the FastPix sandbox is mocked with a 100-second ready asset
    And the following "activities" exist:
      | activity | name      | course | completion_watch_percent | grademax |
      | fastpix  | Lecture 1 | C1     | 80                        | 100      |
    And I log in as "student1"

  Scenario: Watching 80%+ completes activity and writes grade
    When I follow "Lecture 1"
    And the player records 80 watched seconds
    Then I should see the completion check on "Lecture 1"
    And the gradebook should show 100 for "Lecture 1"

  Scenario: Watching 79% leaves activity incomplete
    When I follow "Lecture 1"
    And the player records 79 watched seconds
    Then "Lecture 1" should NOT be marked complete
    And the gradebook should be empty for "Lecture 1"

  Scenario: Re-entering a completed activity does not re-write grade
    Given I have completed "Lecture 1" with grade 100
    And the gradebook timestamp for "Lecture 1" is recorded as T
    When I follow "Lecture 1"
    And the player records 100 watched seconds
    Then the gradebook timestamp for "Lecture 1" should still be T
```

### 4. no_skip_enforcement.feature (Phase D)

```gherkin
@mod @mod_fastpix
Feature: No-skip mode rejects forward seeks server-side
  When an activity has no_skip_required=1, forward seeks are recorded as fraud

  Background:
    Given the FastPix sandbox is mocked with a 100-second ready asset with no_skip_required=true
    And a student is enrolled and watching the activity

  Scenario: Forward seek is recorded as fraud
    When the player records 50 watched seconds
    And the player emits a seek event (client_seek_count=1)
    And the player records 51 watched seconds
    Then the attempt's fraud_count should be at least 1
    And the attempt's last_fraud_reason should be "seek_on_noskip"
    And the attempt's watched_seconds should be 50    # not updated on fraud

  Scenario: Backward seek (replay) does not flag fraud
    When the player records 50 watched seconds
    And the player emits a seek event (client_seek_count=1) but watched_seconds stays at 50
    And the player records 50 watched seconds
    Then the attempt's fraud_count should be 0
```

## Validation

- All four `.feature` files run green via `vendor/bin/behat` against a clean Moodle 4.5.
- Each phase's exit criterion has a Behat scenario verifying it.
- Mocked FastPix responses are deterministic (no network calls in CI).
