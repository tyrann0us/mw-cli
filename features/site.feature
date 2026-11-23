Feature: Show information about the wiki

  Scenario: Site info loads MediaWiki and reports the wiki
    When I run "mw site info --field=name"
    Then STDOUT should be:
      """
      Test Wiki
      """
    When I run "mw site info --fields=name,language --format=yaml"
    Then STDOUT should be:
      """
      ---
      name: "Test Wiki"
      language: "en"
      """
    When I run "mw site info --format=json --fields=path"
    Then STDOUT should be:
      """
      {"path":"/var/www/html"}
      """

  Scenario: Site info prints a box table on a terminal
    When I run "script -qec 'mw site info --fields=name' /dev/null"
    Then STDOUT should contain:
      """
      | name  | Test Wiki |
      """

  Scenario: Usage errors are reported before MediaWiki loads
    When I try "cd /tmp && mw site info --nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown parameter: --nope.
      """
