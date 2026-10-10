@block @block_atrisk
Feature: Per-block configuration of the Early Warning block
  As a teacher
  I want the block settings form to validate my input and keep my choices
  So that a mistake cannot break the block

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                 |
      | Course A | C-A       | 1                | ## now -10 weeks ## %s ## |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | T1        | Eacher   |
      | student1 | Alice     | Anderson |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C-A    | editingteacher |
      | student1 | C-A    | student        |
    And the following "blocks" exist:
      | blockname | contextlevel | reference | pagetypepattern | defaultregion |
      | atrisk    | Course       | C-A       | course-view-*   | side-pre      |
    And I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    And I configure the "Solin Early Warning" block
    And I expand all fieldsets

  @javascript
  Scenario: A visible row count above the maximum is rejected
    When I set the field "Number of students to show" to "101"
    And I press "Save changes"
    Then I should see "Enter a number no greater than 100."
    When I set the field "Number of students to show" to "100"
    And I press "Save changes"
    Then I should see "Anderson" in the "Solin Early Warning" "block"

  @javascript
  Scenario: Malformed course breaks are rejected
    When I set the field "Course-specific breaks" to "next week"
    And I press "Save changes"
    Then I should see "expected \"YYYY-MM-DD, YYYY-MM-DD\""
    When I set the field "Course-specific breaks" to "2020-01-01, 2020-01-07"
    And I press "Save changes"
    Then I should see "Anderson" in the "Solin Early Warning" "block"

  @javascript
  Scenario: The course shape override and the other advanced overrides are saved
    Then I should see "Detected shape: not yet computed."
    And the field "Course shape" matches value "Auto-detect (default)"
    When I set the following fields to these values:
      | Course shape           | Self-paced                 |
      | Forum-silence signal   | Force on                   |
      | Peer-comparison scope  | Only the viewer's groups   |
      | Skip ended courses     | Force off                  |
    And I press "Save changes"
    And I configure the "Solin Early Warning" block
    And I expand all fieldsets
    Then the following fields match these values:
      | Course shape           | Self-paced                 |
      | Forum-silence signal   | Force on                   |
      | Peer-comparison scope  | Only the viewer's groups   |
      | Skip ended courses     | Force off                  |

  @javascript
  Scenario: The form shows the course shape once the detection task has run
    Given I run the scheduled task "\block_atrisk\task\detect_course_shapes"
    And I am on "Course A" course homepage
    When I configure the "Solin Early Warning" block
    And I expand all fieldsets
    Then I should see "Detected shape:"
    And I should not see "Detected shape: not yet computed."
