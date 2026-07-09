@tool @tool_imageextractor
Feature: Create and scope image extraction jobs
  In order to export images in bulk
  As an administrator
  I need to create extraction jobs, scope them and preview how many files match

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | tool_imageextractor |
    And I log in as "admin"

  Scenario: Create an extraction job and preview the estimate without JavaScript
    When I visit "/admin/tool/imageextractor/index.php"
    And I press "New extraction job"
    And I set the field "Job name" to "All site images"
    And I press "Estimate matches"
    Then I should see "currently match about"
    When I press "Save changes"
    Then I should see "Job saved."
    When I visit "/admin/tool/imageextractor/index.php"
    Then I should see "All site images"
    And I should see "Extract"

  @javascript
  Scenario: The live estimate updates and a job can be scoped to a category
    Given the following "categories" exist:
      | name    | category | idnumber |
      | Science | 0        | SCI      |
    When I visit "/admin/tool/imageextractor/index.php"
    And I press "New extraction job"
    And I expand all fieldsets
    And I set the field "Job name" to "Science images"
    And I set the field "Component" to "mod_forum"
    Then I should see "files" in the "[data-region='tool_imageextractor-estimate']" "css_element"
    When I set the field "Course categories" to "Science"
    And I press "Save changes"
    Then I should see "Job saved."
