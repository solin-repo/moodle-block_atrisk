@block @block_atrisk
Feature: View all flagged students page
  As a teacher
  I want a paginated page listing every flagged student
  So that I can review students beyond the in-block top N

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                 |
      | Course P | C-P       | 1                | ## now -10 weeks ## %s ## |
    And the following "users" exist:
      | username  | firstname | lastname  |
      | teacher1  | T1        | Eacher    |
      | learner01 | Student   | Learner01 |
      | learner02 | Student   | Learner02 |
      | learner03 | Student   | Learner03 |
      | learner04 | Student   | Learner04 |
      | learner05 | Student   | Learner05 |
      | learner06 | Student   | Learner06 |
      | learner07 | Student   | Learner07 |
      | learner08 | Student   | Learner08 |
      | learner09 | Student   | Learner09 |
      | learner10 | Student   | Learner10 |
      | learner11 | Student   | Learner11 |
      | learner12 | Student   | Learner12 |
      | learner13 | Student   | Learner13 |
      | learner14 | Student   | Learner14 |
      | learner15 | Student   | Learner15 |
      | learner16 | Student   | Learner16 |
      | learner17 | Student   | Learner17 |
      | learner18 | Student   | Learner18 |
      | learner19 | Student   | Learner19 |
      | learner20 | Student   | Learner20 |
      | learner21 | Student   | Learner21 |
      | learner22 | Student   | Learner22 |
      | learner23 | Student   | Learner23 |
      | learner24 | Student   | Learner24 |
      | learner25 | Student   | Learner25 |
      | learner26 | Student   | Learner26 |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | teacher1  | C-P    | editingteacher |
      | learner01 | C-P    | student |
      | learner02 | C-P    | student |
      | learner03 | C-P    | student |
      | learner04 | C-P    | student |
      | learner05 | C-P    | student |
      | learner06 | C-P    | student |
      | learner07 | C-P    | student |
      | learner08 | C-P    | student |
      | learner09 | C-P    | student |
      | learner10 | C-P    | student |
      | learner11 | C-P    | student |
      | learner12 | C-P    | student |
      | learner13 | C-P    | student |
      | learner14 | C-P    | student |
      | learner15 | C-P    | student |
      | learner16 | C-P    | student |
      | learner17 | C-P    | student |
      | learner18 | C-P    | student |
      | learner19 | C-P    | student |
      | learner20 | C-P    | student |
      | learner21 | C-P    | student |
      | learner22 | C-P    | student |
      | learner23 | C-P    | student |
      | learner24 | C-P    | student |
      | learner25 | C-P    | student |
      | learner26 | C-P    | student |
    And the following "blocks" exist:
      | blockname | contextlevel | reference | pagetypepattern | defaultregion |
      | atrisk    | Course       | C-P       | course-view-*   | side-pre      |

  Scenario: The page shows 25 students per page and keeps the expand-all state across pages
    Given I am on the "C-P" "block_atrisk > View all" page logged in as "teacher1"
    Then I should see "Learner01"
    And I should see "Learner25"
    And I should not see "Learner26"
    And ".block_atrisk_details[open]" "css_element" should not exist
    When I click on "Expand all" "link"
    Then the url should match "/blocks/atrisk/view\.php\?courseid=\d+&expandall=1$"
    And ".block_atrisk_details[open]" "css_element" should exist
    And I should see "Collapse all"
    When I click on "a[href*='page=1']" "css_element" in the ".pagination" "css_element"
    Then I should see "Learner26"
    And I should not see "Learner01"
    And the url should match "expandall=1"
    And ".block_atrisk_details[open]" "css_element" should exist
    When I click on "Collapse all" "link"
    Then ".block_atrisk_details[open]" "css_element" should not exist
    And I should see "Expand all"

  Scenario: A student cannot open the view all page
    When I log in as "learner01"
    Then opening the Early Warning "View all" page for "C-P" should fail with "you do not currently have permissions"

  Scenario: Changing the sensitivity on the view all page returns to the same page and state
    Given I am on the "C-P" "block_atrisk > View all" page logged in as "teacher1"
    And I click on "Expand all" "link"
    And ".block_atrisk_preset_btn_active" "css_element" should exist
    And I should see "Default" in the ".block_atrisk_preset_btn_active" "css_element"
    When I click on "a.block_atrisk_preset_btn[href*='preset=fewer']" "css_element"
    Then the url should match "/blocks/atrisk/view\.php\?courseid=\d+&expandall=1$"
    And I should see "Fewer" in the ".block_atrisk_preset_btn_active" "css_element"
    And I should see "Collapse all"
    When I click on "a.block_atrisk_preset_btn[href*='preset=more']" "css_element"
    Then I should see "More" in the ".block_atrisk_preset_btn_active" "css_element"

  Scenario: Pausing from the view all page returns to the view all page
    Given I am on the "C-P" "block_atrisk > View all" page logged in as "teacher1"
    When I click on "Pause for one week" "link"
    Then the url should match "/blocks/atrisk/view\.php\?courseid=\d+$"
    And I should see "Currently in a configured break"
    And I should not see "Pause for one week"
    And I should see "Learner01"
