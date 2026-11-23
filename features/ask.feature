Feature: Run Semantic MediaWiki queries

  @smw
  Scenario: Ask lists the pages a query finds with their printouts
    Given a file "/tmp/pages/Town_Hall" with:
      """
      [[Category:Building]] [[Street::Main Street]] [[Floors::3]]
      """
    And a file "/tmp/pages/Library" with:
      """
      [[Category:Building]] [[Street::Side Street]] [[Street::Corner]]
      """
    When I run "mw maint importTextFiles /tmp/pages/Town_Hall /tmp/pages/Library"
    And I run "mw ask '[[Category:Building]]|?Floors|?Street'"
    Then STDOUT should be:
      """
      title	Floors	Street
      Library		Side Street, Corner
      Town Hall	3	Main Street
      """
    When I run "mw ask '[[Category:Building]]|?Street' --limit=1 --format=json"
    Then STDOUT should be:
      """
      [{"title":"Library","Street":"Side Street, Corner"}]
      """
    When I run "mw ask '[[Street::Main Street]]' --format=ids"
    Then STDOUT should be:
      """
      Town Hall
      """
    When I run "mw ask '[[Street::Main Street||Corner]]|mainlabel=-' --format=count"
    Then STDOUT should be:
      """
      2
      """
    When I run "mw ask '[[Category:Nothing]]|?Street' --format=csv"
    Then STDOUT should be:
      """
      title,Street
      """
    When I run "mw ask '[[Category:Building]]|format=debug' --format=count"
    Then STDOUT should be:
      """
      2
      """

  @smw
  Scenario: Ask rejects invalid queries and limits
    When I try "mw ask '[[Category:'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: The part "" of the query was not understood.
      """
    When I try "mw ask '[[Category:Building]]' --limit=ten"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a number.
      """
    When I try "mw ask '[[Category:Building]]' --limit"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a number.
      """

  # Runs in every job: the copy of LocalSettings.php without SMW covers this path in the SMW job too.
  Scenario: Ask fails when Semantic MediaWiki isn't loaded
    When I try "sed '/SemanticMediaWiki/d' LocalSettings.php > /tmp/NoSmw.php && MW_CONFIG_FILE=/tmp/NoSmw.php mw ask '[[Category:Building]]'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Semantic MediaWiki is not installed on this wiki.
      """
