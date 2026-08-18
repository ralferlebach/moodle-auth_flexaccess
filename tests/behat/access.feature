@auth @auth_flexaccess
Feature: FlexAccess authentication method
  In order to offer low-barrier temporary access
  As an administrator
  I need the FlexAccess authentication method to be installed and manageable

  Scenario: The FlexAccess authentication method is listed for administrators
    Given I log in as "admin"
    When I navigate to "Plugins > Authentication > Manage authentication" in site administration
    Then I should see "FlexAccess authentication"
