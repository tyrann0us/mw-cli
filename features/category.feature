Feature: List category members and the categories of pages

  Background:
    Given a file "/tmp/pages/Apple" with:
      """
      [[Category:Fruit]]
      """
    And a file "/tmp/pages/Banana" with:
      """
      [[Category:Fruit]] [[Category:Yellow]]
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

  Scenario: Members are listed with title, namespace and type
    When I run "mw category members Fruit"
    Then STDOUT should be:
      """
      id	title	namespace	type
      2	Apple	0	page
      3	Banana	0	page
      4	Category:Citrus	14	subcat
      5	File:Pear	6	file
      """
    When I run "mw category members category:fruit --format=count"
    Then STDOUT should be:
      """
      4
      """

  Scenario: Members can be filtered by type and namespace
    When I run "mw category members Category:Fruit --type=subcat --field=title"
    Then STDOUT should be:
      """
      Category:Citrus
      """
    When I run "mw category members Fruit --namespace=File --fields=title,id --format=csv"
    Then STDOUT should be:
      """
      title,id
      File:Pear,5
      """
    When I run "mw category members Fruit --namespace='Category talk' --format=count"
    Then STDOUT should be:
      """
      0
      """
    When I run "mw category members Fruit --namespace=0 --type=page --format=ids"
    Then STDOUT should be:
      """
      2 3
      """

  Scenario: A category name in another namespace's form is still a category
    When I run "mw category members Help:Guides --field=title"
    Then STDOUT should be:
      """
      Help:Peeling
      """

  Scenario: Members of a category without members or page are empty
    When I run "mw category members Nothing --format=json"
    Then STDOUT should be:
      """
      []
      """

  Scenario: Bad member options are rejected
    When I try "mw category members Fruit --type=video"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid type. Use one of: page, subcat, file.
      """
    When I try "mw category members Fruit --namespace=Nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown namespace: Nope.
      """
    When I try "mw category members Fruit --namespace=9999"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown namespace: 9999.
      """
    When I try "mw category members Fruit --namespace"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --namespace needs a value.
      """
    When I try "mw category members '<'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid category name: <.
      """
    When I try "mw category members Category:"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid category name: Category:.
      """

  Scenario: Categories of pages, one row per page and category
    When I run "mw category of Banana Apple Main_Page apple"
    Then STDOUT should be:
      """
      title	category
      Banana	Category:Fruit
      Banana	Category:Yellow
      Apple	Category:Fruit
      """

  Scenario: Categories of a missing page fail
    When I try "mw category of Apple Nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Page not found: Nope.
      """
    And STDOUT should be:
      """
      """
