Feature: List categories and the categories of a page

  Background:
    Given a file "/tmp/pages/Apple" with:
      """
      [[Category:Fruit]]
      """
    And a file "/tmp/pages/Banana" with:
      """
      [[Category:Fruit]] [[Category:Fruit/Yellow]]
      """
    And a file "/tmp/pages/Category:Citrus" with:
      """
      [[Category:Fruit]]
      """
    And a file "/tmp/pages/File:Pear" with:
      """
      [[Category:Fruit]]
      """
    And a file "/tmp/pages/Help:Peeling" with:
      """
      [[Category:Help:Guides]]
      """
    When I run "mw maint importTextFiles --quiet /tmp/pages/*"
    # Newer MediaWiki versions update category counts in a job.
    And I run "mw maint runJobs --quiet"

  Scenario: Categories with members are listed with their counts
    # Scoped with --prefix: Semantic MediaWiki adds categories of its own.
    When I run "mw category list --prefix=fruit"
    Then STDOUT should be:
      """
      title	pages	subcats	files
      Fruit	2	1	1
      Fruit/Yellow	1	0	0
      """
    When I run "mw category list --prefix=Fruit/ --format=csv --fields=title,members"
    Then STDOUT should be:
      """
      title,members
      Fruit/Yellow,1
      """
    When I run "mw category list --prefix=Fruit --limit=1 --field=title"
    Then STDOUT should be:
      """
      Fruit
      """
    When I run "mw category list --prefix=Help: --fields=title,members --format=json"
    Then STDOUT should be:
      """
      [{"title":"Help:Guides","members":1}]
      """
    When I run "mw category list --prefix=Nothing --format=count"
    Then STDOUT should be:
      """
      0
      """
    When I run "mw category list --limit=1 --format=count"
    Then STDOUT should be:
      """
      1
      """

  Scenario: An emptied category is not listed
    When I run "echo 'No more fruit' | mw page put Apple && echo 'No more fruit' | mw page put Banana"
    And I run "echo 'Gone' | mw page put Category:Citrus && echo 'Gone' | mw page put File:Pear"
    And I run "mw maint runJobs --quiet"
    And I run "mw category list --prefix=Fruit --format=count"
    Then STDOUT should be:
      """
      0
      """

  Scenario: Categories of a page, sorted by name
    When I run "mw page category list Banana"
    Then STDOUT should be:
      """
      title
      Fruit
      Fruit/Yellow
      """
    When I run "mw page category list banana --limit=1 --field=title"
    Then STDOUT should be:
      """
      Fruit
      """
    When I run "mw page category list Main_Page --field=title --format=json"
    Then STDOUT should be:
      """
      []
      """
    When I run "mw page category list Main_Page --format=count"
    Then STDOUT should be:
      """
      0
      """

  Scenario: Categories of a missing or invalid page fail
    When I try "mw page category list Nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Page 'Nope' doesn't exist.
      """
    And STDOUT should be:
      """
      """
    When I try "mw page category list '<'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid title '<'.
      """
    When I try "mw page category list"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Missing argument: title.
      """

  Scenario: A bad limit fails on every list command
    When I try "mw category list --limit=0"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a positive number.
      """
    When I try "mw page category list Banana --limit=two"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a positive number.
      """
    When I try "mw page list --limit=-1"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a positive number.
      """
    When I try "mw backlinks Apple --limit=1.5"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a positive number.
      """
    When I try "mw transclusions Apple --limit="
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a positive number.
      """
