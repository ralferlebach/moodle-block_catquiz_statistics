@block @block_catquiz_statistics @block_catquiz_statistics_visibility
Feature: CAT Quiz Statistics block visibility and access control
  As a site administrator
  I want the CAT Quiz Statistics block to be visible only to users with the view capability
  So that statistics are shown only to course teachers and managers

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the catquiz_statistics block is added to the "C1" course

  @javascript
  Scenario: Editing teacher sees the CAT Quiz Statistics block
    Given I log in as "teacher1"
    When I am on "C1" course homepage
    Then I should see "CAT Quiz Statistics"

  @javascript
  Scenario: Student does not see the report link in the block
    Given I log in as "student1"
    When I am on "C1" course homepage
    Then the catquiz_statistics report link should not be visible

  @javascript
  Scenario: Editing teacher can follow the report link
    Given I log in as "teacher1"
    When I am on "C1" course homepage
    Then the catquiz_statistics report link should be visible
