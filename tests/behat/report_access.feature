@block @block_catquiz_statistics @block_catquiz_statistics_report
Feature: CAT Quiz Statistics report page access control
  As a site administrator
  I want report.php to be accessible to users with the required capabilities
  So that authorised staff can view student statistics

  # Note: The student-denied-access case is covered by PHPUnit (access_test).
  # Testing capability denial in Behat is avoided because require_capability()
  # raises a Moodle exception that behat_hooks::look_for_exceptions() would
  # intercept as a test failure even on an intentional access-denied page.
  # For Behat we therefore only test the positive (authorised) path.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |

  Scenario: Administrator can access the course report page
    Given I log in as "admin"
    When I am on the "C1" course "catquiz_statistics report" page
    Then I should see "CAT Quiz Statistics"
