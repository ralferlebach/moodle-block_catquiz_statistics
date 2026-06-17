@block @block_catquizstatistics @block_catquizstatistics_report
Feature: CAT Quiz Statistics report page access control
  As a site administrator
  I want report.php to be restricted to users with viewdetails capability
  So that personal student data is protected

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

  @javascript
  Scenario: Editing teacher can access the course report page
    Given I log in as "teacher1"
    When I am on the "C1" course "catquizstatistics report" page
    Then I should see "CAT Quiz Statistics"

  @javascript
  Scenario: Student is denied access to the course report page
    Given I log in as "student1"
    When I am on the "C1" course "catquizstatistics report" page
    Then I should see "You do not have permission"
