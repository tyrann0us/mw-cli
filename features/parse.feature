Feature: Render wikitext to HTML

  Scenario: Parse renders a file or stdin
    Given a file "/tmp/page.wiki" with:
      """
      ''Hello'' [[Main Page]] on {{PAGENAME}}
      """
    When I run "mw parse /tmp/page.wiki"
    Then STDOUT should contain:
      """
      <p><i>Hello</i> <a href="/index.php/Main_Page" title="Main Page">Main Page</a> on API
      """
    When I run "cd /tmp && mw --path=/var/www/html parse --title=Sandbox /dev/stdin < page.wiki"
    Then STDOUT should contain:
      """
      on Sandbox
      """

  Scenario: Parse rejects a missing file and an invalid title
    When I try "mw parse /tmp/missing.wiki"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Can't read '/tmp/missing.wiki'.
      """
    When I try "echo x | mw parse --title='<>'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid title '<>'.
      """
