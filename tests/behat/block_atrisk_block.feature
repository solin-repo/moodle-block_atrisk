@block @block_atrisk
Feature: Solin Early Warning block surfaces flagged students in a course
  As a teacher
  I want the Early Warning block to render students at risk in my course
  So that I can intervene before they disengage

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                    |
      | Course A | C-A       | 1                | ## now -10 weeks ## %s ##    |
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

  @javascript
  Scenario: Adding the block renders an empty state when no signals fire
    Given I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    When I add the "Solin Early Warning" block
    Then "Solin Early Warning" "block" should exist

  @javascript
  Scenario: Block surfaces inactive students past calibration window
    Given I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    When I add the "Solin Early Warning" block
    # Both students have no user_lastaccess for this course → inactivity fires.
    Then I should see "Anderson" in the "Solin Early Warning" "block"
    And I should see "Brown" in the "Solin Early Warning" "block"

  @javascript
  Scenario: Student does not see the block
    Given I log in as "student1"
    And I am on "Course A" course homepage
    Then "Solin Early Warning" "block" should not exist

  @javascript
  Scenario: Rows are collapsed by default and expand on click
    Given I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    When I add the "Solin Early Warning" block
    # Collapsed first line shows severity + name; signal explanations are hidden.
    Then I should see "Anderson" in the "Solin Early Warning" "block"
    And ".block_atrisk_details:not([open]) .block_atrisk_signals" "css_element" should exist
    # Clicking Expand all reveals the signal list for every row.
    When I click on "Expand all" "link" in the "Solin Early Warning" "block"
    Then ".block_atrisk_details[open]" "css_element" should exist

  @javascript
  Scenario: Per-instance topn override truncates the list and view.php exposes the preset control
    Given I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    And I add the "Solin Early Warning" block
    # Both students initially visible.
    And I should see "Anderson" in the "Solin Early Warning" "block"
    And I should see "Brown" in the "Solin Early Warning" "block"
    # Override the visible row count to 1 via the block edit form.
    When I configure the "Solin Early Warning" block
    And I expand all fieldsets
    And I set the field "Number of students to show" to "1"
    And I press "Save changes"
    # Anderson sorts above Brown (same severity, signal count, percentile → name).
    Then I should see "Anderson" in the "Solin Early Warning" "block"
    And I should not see "Brown" in the "Solin Early Warning" "block"
    # The "View all" link now appears (2 flagged > topn 1) and view.php shows the Sensitivity preset row.
    When I click on "View all flagged students (2)" "link" in the "Solin Early Warning" "block"
    Then I should see "Show"
    And I should see "More"
    And I should see "Default"
    And I should see "Fewer"
    And I should see "Anderson"
    And I should see "Brown"

  @javascript
  Scenario: Pause for one week shows informational banner alongside the flagged list
    Given I log in as "teacher1"
    And I am on "Course A" course homepage with editing mode on
    And I add the "Solin Early Warning" block
    And I should see "Anderson" in the "Solin Early Warning" "block"
    # Click the inline pause link → break banner appears, list stays visible.
    When I click on "Pause for one week" "link" in the "Solin Early Warning" "block"
    Then I should see "Currently in a configured break" in the "Solin Early Warning" "block"
    # The list still renders so a teacher preparing for resumption can see pre-break flags.
    And I should see "Anderson" in the "Solin Early Warning" "block"
    # Click resume → banner clears.
    When I click on "Resume now" "link" in the "Solin Early Warning" "block"
    Then I should see "Anderson" in the "Solin Early Warning" "block"
    And I should not see "Currently in a configured break" in the "Solin Early Warning" "block"

  @javascript
  Scenario: SEPARATEGROUPS restricts the list to the viewer's groups
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion | startdate                    | groupmode | groupmodeforce |
      | Course B | C-B       | 1                | ## now -10 weeks ## %s ##    | 1         | 1              |
    And the following "users" exist:
      | username     | firstname | lastname |
      | t-allgroups  | Manager   | Em       |
      | t-grouped    | Quinn     | Quincy   |
      | s-ina        | Alice     | Aves     |
      | s-inb        | Bob       | Borg     |
    And the following "course enrolments" exist:
      | user         | course | role           |
      | t-allgroups  | C-B    | editingteacher |
      | t-grouped    | C-B    | teacher        |
      | s-ina        | C-B    | student        |
      | s-inb        | C-B    | student        |
    And the following "groups" exist:
      | name | course | idnumber |
      | GA   | C-B    | ga       |
      | GB   | C-B    | gb       |
    And the following "group members" exist:
      | user      | group |
      | t-grouped | ga    |
      | s-ina     | ga    |
      | s-inb     | gb    |
    # Editingteacher (has accessallgroups by default) adds the block — sees both students.
    And I log in as "t-allgroups"
    And I am on "Course B" course homepage with editing mode on
    And I add the "Solin Early Warning" block
    Then I should see "Aves" in the "Solin Early Warning" "block"
    And I should see "Borg" in the "Solin Early Warning" "block"
    And I log out
    # Non-editing teacher in group A only — must see Aves but NOT Borg.
    When I log in as "t-grouped"
    And I am on "Course B" course homepage
    Then I should see "Aves" in the "Solin Early Warning" "block"
    And I should not see "Borg" in the "Solin Early Warning" "block"
