@block @block_atrisk
Feature: Site administration of the Early Warning block
  As a site administrator
  I want to configure the block site-wide and export readiness data
  So that the heuristics suit this site

  Scenario: The settings page rejects an invalid breaks calendar and saves a valid one
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=blocksettingatrisk"
    And I should see "Breaks calendar"
    And I should see "Default visible row count"
    When I set the field "Breaks calendar" to "2026/12/21, 2027-01-03"
    And I press "Save changes"
    Then I should see "is not a valid YYYY-MM-DD date"
    When I set the field "Breaks calendar" to "2026-13-45, 2026-12-31"
    And I press "Save changes"
    Then I should see "could not be parsed as a date"
    When I set the field "Breaks calendar" to multiline:
      """
      # Winter break
      2026-12-21, 2027-01-03
      """
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Breaks calendar" matches multiline:
      """
      # Winter break
      2026-12-21, 2027-01-03
      """

  Scenario: The settings page rejects a break that ends before it starts
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=blocksettingatrisk"
    When I set the field "Breaks calendar" to "2026-12-31, 2026-12-01"
    And I press "Save changes"
    Then I should see "start date is after end date"

  @javascript
  Scenario: An administrator opens the readiness export page and downloads the JSON
    Given the following "courses" exist:
      | fullname          | shortname | enablecompletion |
      | Readiness course  | RC-1      | 1                |
    And the following "blocks" exist:
      | blockname | contextlevel | reference | pagetypepattern | defaultregion |
      | atrisk    | Course       | RC-1      | course-view-*   | side-pre      |
    And I log in as "admin"
    When I navigate to "Plugins > Blocks > Solin Early Warning: readiness data export" in site administration
    Then I should see "Readiness data export"
    And I should see "Include course names"
    And I should see "Generate JSON file"
    And the Early Warning readiness export should contain "\"schema_version\""
    And the Early Warning readiness export should contain "\"course.fullname\""
    And the Early Warning readiness export should not contain "Readiness course"
    When I set the field "Include course names" to "1"
    Then the Early Warning readiness export should contain "Readiness course"

  Scenario: A teacher cannot open the readiness export page
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | T1        | Eacher   |
    When I log in as "teacher1"
    Then opening the Early Warning page "/blocks/atrisk/readiness.php" should fail with "Access denied"
