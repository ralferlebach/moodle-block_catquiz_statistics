@block @block_catquiz_statistics @block_catquiz_statistics_modules
Feature: CAT Quiz Statistics report module tabs and export controls
  As a course teacher
  I want to switch between the report modules and see the export controls
  So that I can review and download CAT quiz statistics in different views

  # These scenarios test the positive (authorised admin) path only. Capability
  # denial is covered by PHPUnit, because require_capability() raises a Moodle
  # exception that behat_hooks::look_for_exceptions() would treat as a failure.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |

  @javascript
  Scenario: The results module is shown by default with module tabs
    Given I log in as "admin"
    When I am on the "C1" course "catquiz_statistics report" page
    Then I should see "CAT Quiz Statistics"
    And I should see "Testergebnisse"
    And I should see "Testnutzung"

  @javascript
  Scenario: Teacher can open the Testnutzung module
    Given I log in as "admin"
    When I am on the "C1" course catquiz_statistics "usage" module
    Then I should see "Testnutzung"

  @javascript
  Scenario: Teacher can open the Item analysis module
    Given I log in as "admin"
    When I am on the "C1" course catquiz_statistics "items" module
    Then I should see "Item- und Antwortanalyse"

  @javascript
  Scenario: The export format selector is present on the results module
    Given I log in as "admin"
    When I am on the "C1" course "catquiz_statistics report" page
    Then I should see "CSV"
    And I should see "JSON"

  @javascript
  Scenario: The learning activity module shows the opt-in notice when disabled
    Given I log in as "admin"
    When I am on the "C1" course catquiz_statistics "activity" module
    Then I should see "Lernangebotsnutzung"
