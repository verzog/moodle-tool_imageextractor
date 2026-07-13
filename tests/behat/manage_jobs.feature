@tool @tool_imageextractor
Feature: Create and scope image selection jobs
  In order to export or replace images in bulk
  As an administrator
  I need to create a job that selects files, then choose what to do with them

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | tool_imageextractor |
    And I log in as "admin"

  Scenario: Create a criteria-only job
    When I visit "/admin/tool/imageextractor/index.php"
    And I press "New job"
    And I set the field "Job name" to "All site images"
    And I press "Save changes"
    Then I should see "Job saved."
    When I visit "/admin/tool/imageextractor/index.php"
    Then I should see "All site images"
    And I should see "Not chosen yet"

  @javascript
  Scenario: A CSV match list hides the criteria fields
    When I visit "/admin/tool/imageextractor/index.php"
    And I press "New job"
    And I expand all fieldsets
    Then I should see "MIME types"
    And I should see "Course categories"
    When I set the field "Select files using" to "A CSV match list (exact filenames or content hashes)"
    Then I should not see "MIME types"
    And I should not see "Course categories"
    And I should see "CSV file"

  @javascript
  Scenario: The results page offers an Extract action after analysing
    When I visit "/admin/tool/imageextractor/index.php"
    And I press "New job"
    And I set the field "Job name" to "Analyse me"
    And I press "Save changes"
    Then I should see "Job saved."
    And I should see "Analyse"
