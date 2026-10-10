@block @block_atrisk
Feature: Teacher actions on the Early Warning block
  As a teacher
  I want to dismiss flags, change the sensitivity and pause the block
  So that the list matches what I need to act on this week

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                 |
      | Course A | C-A       | 1                | ## now -10 weeks ## %s ## |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | T1        | Eacher   |
      | student1 | Alice     | Anderson |
      | student2 | Bob       | Brown    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C-A    | editingteacher |
      | student1 | C-A    | student        |
      | student2 | C-A    | student        |
    And the following "blocks" exist:
      | blockname | contextlevel | reference | pagetypepattern | defaultregion |
      | atrisk    | Course       | C-A       | course-view-*   | side-pre      |

  @javascript
  Scenario: Dismissing a flag hides the student until the dismissal is undone
    Given I am on the "Course A" course page logged in as teacher1
    And I should see "Anderson" in the "Solin Early Warning" "block"
    And I click on "Expand all" "link" in the "Solin Early Warning" "block"
    When I click on "Dismiss for one week" "link" in the "//li[contains(@class, 'block_atrisk_row')][contains(., 'Anderson')]" "xpath_element"
    Then I should not see "Anderson" in the "Solin Early Warning" "block"
    And I should see "Brown" in the "Solin Early Warning" "block"
    When I undo the Early Warning dismissal of "student1" in course "C-A"
    Then I should see "Anderson" in the "Solin Early Warning" "block"
    And I should see "Brown" in the "Solin Early Warning" "block"

  @javascript
  Scenario: Teachers without the dismiss capability see no dismiss action
    Given the following "permission overrides" exist:
      | capability              | permission | role           | contextlevel | reference |
      | block/atrisk:dismissflag | Prohibit   | editingteacher | Course       | C-A       |
    When I am on the "Course A" course page logged in as teacher1
    And I click on "Expand all" "link" in the "Solin Early Warning" "block"
    Then I should see "View outline" in the "Solin Early Warning" "block"
    And I should not see "Dismiss for one week" in the "Solin Early Warning" "block"

  @javascript
  Scenario: Switching the sensitivity in the block returns to the course page with the new preset active
    Given I am on the "Course A" course page logged in as teacher1
    And I should see "Default" in the ".block_atrisk_preset_btn_active" "css_element"
    When I click on "a.block_atrisk_preset_btn[href*='preset=fewer']" "css_element"
    Then I should see "Course A" in the "page-header" "region"
    And I should see "Fewer" in the ".block_atrisk_preset_btn_active" "css_element"
    When I click on "a.block_atrisk_preset_btn[href*='preset=more']" "css_element"
    Then I should see "More" in the ".block_atrisk_preset_btn_active" "css_element"
    When I click on "a.block_atrisk_preset_btn[href*='preset=default']" "css_element"
    Then I should see "Default" in the ".block_atrisk_preset_btn_active" "css_element"

  @javascript
  Scenario: The sensitivity control is hidden when the site disables it
    Given the following config values are set as admin:
      | sensitivity_preset_enabled | 0 | block_atrisk |
    When I am on the "Course A" course page logged in as teacher1
    Then I should see "Anderson" in the "Solin Early Warning" "block"
    And ".block_atrisk_preset" "css_element" should not exist

  @javascript
  Scenario: Resuming removes only the break that covers today and keeps other course breaks
    Given I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    And I configure the "Solin Early Warning" block
    And I expand all fieldsets
    And I set the field "Course-specific breaks" to multiline:
      """
      # Spring holiday
      2020-04-01, 2020-04-07
      """
    And I press "Save changes"
    When I click on "Pause for one week" "link" in the "Solin Early Warning" "block"
    Then I should see "Currently in a configured break" in the "Solin Early Warning" "block"
    When I click on "Resume now" "link" in the "Solin Early Warning" "block"
    Then I should not see "Currently in a configured break" in the "Solin Early Warning" "block"
    And I configure the "Solin Early Warning" block
    And I expand all fieldsets
    And the field "Course-specific breaks" matches multiline:
      """
      # Spring holiday
      2020-04-01, 2020-04-07
      """
