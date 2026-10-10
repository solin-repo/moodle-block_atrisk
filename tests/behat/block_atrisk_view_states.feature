@block @block_atrisk
Feature: View all page states and endpoint parameter validation
  As a teacher
  I want the View all page to explain why it shows no students
  So that an empty list is never ambiguous

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | T1        | Eacher   |
      | student1 | Alice     | Anderson |
      | student2 | Bob       | Brown    |

  Scenario: An ended course shows the course-ended state instead of a list
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                 | enddate                  |
      | Course E | C-E       | 1                | ## now -20 weeks ## %s ## | ## now -1 weeks ## %s ## |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C-E    | editingteacher |
      | student1 | C-E    | student        |
    When I am on the "C-E" "block_atrisk > View all" page logged in as "teacher1"
    Then I should see "This course has ended, so no students are being monitored."
    And I should not see "Anderson"

  Scenario: Under separate groups a teacher sees only their own group, or a notice when in none
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                 | groupmode | groupmodeforce |
      | Course G | C-G       | 1                | ## now -10 weeks ## %s ## | 1         | 1              |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher1 | C-G    | teacher |
      | student1 | C-G    | student |
      | student2 | C-G    | student |
    When I am on the "C-G" "block_atrisk > View all" page logged in as "teacher1"
    Then I should see "You are not a member of any group in this course."
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group 1 | C-G    | G1       |
    And the following "group members" exist:
      | user     | group |
      | teacher1 | G1    |
      | student1 | G1    |
    And I am on the "C-G" "block_atrisk > View all" page
    And I should see "Anderson"
    And I should not see "Brown"

  Scenario: The sensitivity and pause endpoints reject unknown values
    Given the following "courses" exist:
      | fullname | shortname |
      | Course V | C-V       |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C-V    | editingteacher |
    And I log in as "teacher1"
    Then opening the Early Warning page "/blocks/atrisk/preset.php?blockinstanceid=1&preset=extreme" should fail with "Invalid sensitivity preset."
    And opening the Early Warning page "/blocks/atrisk/pause.php?blockinstanceid=1&action=freeze" should fail with "Invalid action parameter"
