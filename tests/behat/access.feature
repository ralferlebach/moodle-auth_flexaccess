@auth_flexaccess
Feature: FlexAccess scaffold
  Scenario: FlexAccess access endpoint is installable
    Given I log in as "admin"
    When I visit "/auth/flexaccess/access.php"
    Then I should see "FlexAccess scaffold"
