Feature: Search and replace text in many pages

  Scenario: A dry run prints a diff and saves nothing
    Given a wiki
    And a file "/tmp/foo.wiki" with:
      """
      Hello world
      second line
      third line
      fourth line
      """
    When I run "mw page put Foo /tmp/foo.wiki && echo 'No match' | mw page put Bar"
    And I run "mw search-replace '/(w)orld|fourth/i' '$1ORLD' --dry-run"
    Then STDOUT should be:
      """
      --- Foo
      +++ Foo
      @@ -1,4 +1,4 @@
      -Hello world
      +Hello wORLD
       second line
       third line
      -fourth line
      +ORLD line
      Success: Would change 1 page, 2 replacements.
      """
    When I run "mw page get Foo"
    Then STDOUT should contain:
      """
      Hello world
      """

  Scenario: Replace in all content pages after confirmation
    When I run "echo 'Hello world' | mw page put Foo && echo 'world' | mw page put Help:Foo"
    And I try "mw search-replace '/world/' 'there'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: No answer on stdin. Pass --yes to run without confirmation.
      """
    When I run "echo n | mw search-replace '/world/' 'there'"
    Then STDERR should contain:
      """
      Change 1 page, 1 replacement? [y/n]
      """
    When I run "echo y | mw search-replace '/world/' 'there'"
    Then STDOUT should be:
      """
      Success: Saved 'Foo' as revision 4 (1 replacement).
      Success: Changed 1 page.
      """
    When I run "mw maint sql --query='SELECT actor_name, comment_text, rev_minor_edit FROM revision JOIN actor ON rev_actor = actor_id JOIN comment ON rev_comment_id = comment_id WHERE rev_id = 4'"
    Then STDOUT should contain:
      """
          [actor_name] => Maintenance script
          [comment_text] => Replace /world/ with "there" (mw search-replace)
          [rev_minor_edit] => 0
      """
    When I run "mw page get Help:Foo"
    Then STDOUT should be:
      """
      world
      """
    When I run "mw search-replace '/world/' 'there'"
    Then STDOUT should be:
      """
      Success: Nothing to replace.
      """

  Scenario: Scope by category and namespace
    When I run "echo 'a [[Category:Fruit]]' | mw page put Apple && echo 'a [[Category:Fruit]]' | mw page put Help:Apple"
    And I run "echo 'a' | mw page put Help:Banana"
    And I run "mw --user=Admin search-replace '/a /' 'b ' --category=Category:Fruit --namespace=Help --summary=Fruit --minor --yes"
    Then STDOUT should be:
      """
      Success: Saved 'Help:Apple' as revision 5 (1 replacement).
      Success: Changed 1 page.
      """
    When I run "mw maint sql --query='SELECT actor_name, comment_text, rev_minor_edit FROM revision JOIN actor ON rev_actor = actor_id JOIN comment ON rev_comment_id = comment_id WHERE rev_id = 5'"
    Then STDOUT should contain:
      """
          [actor_name] => Admin
          [comment_text] => Fruit
          [rev_minor_edit] => 1
      """
    When I run "mw search-replace '/^a/' 'c' --category=Fruit --dry-run"
    Then STDOUT should contain:
      """
      +++ Apple
      """
    And STDOUT should contain:
      """
      Success: Would change 1 page, 1 replacement.
      """
    When I run "mw search-replace '/^a/' 'c' --namespace=Help --dry-run"
    Then STDOUT should contain:
      """
      +++ Help:Banana
      """
    And STDOUT should contain:
      """
      Success: Would change 1 page, 1 replacement.
      """

  Scenario: Everything is checked before the first save
    When I run "echo 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!b' | mw page put Foo"
    And I run "echo '[1]' | mw page put MediaWiki:Foo.json"
    And I try "mw search-replace '/foo' x"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid regex '/foo': No ending delimiter '/' found.
      """
    When I try "mw search-replace '/a/' x --namespace=Nope"
    Then STDERR should contain:
      """
      Error: Unknown namespace 'Nope'.
      """
    When I try "mw search-replace '/a/' x --category=Help:Fruit"
    Then STDERR should contain:
      """
      Error: Invalid category 'Help:Fruit'.
      """
    When I try "mw --user=Nobody search-replace '/a/' x"
    Then STDERR should contain:
      """
      Error: Invalid user 'Nobody'.
      """
    When I try "mw search-replace '/(*LIMIT_MATCH=1000)(a+)+b/' x --yes"
    Then STDERR should contain:
      """
      Error: Regex failed on 'Foo': Backtrack limit exhausted.
      """
    When I try "mw search-replace '/]/' '' --namespace=MediaWiki --yes"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: The replacement would make 'MediaWiki:Foo.json' invalid json content.
      """
    When I run "mw maint sql --query='SELECT COUNT(*) AS n FROM revision'"
    Then STDOUT should contain:
      """
          [n] => 3
      """

  Scenario: Pages edited after they were read are skipped
    When I run "echo a | mw page put Foo && echo a | mw page put Bar"
    And I run "rm -f /tmp/err; (until grep -q 'y/n' /tmp/err 2>/dev/null; do sleep 0.2; done; echo b | mw page put Bar >/dev/null; echo y) | mw search-replace '/^a$/' 'c' 2>/tmp/err; cat /tmp/err >&2; rm /tmp/err"
    Then STDOUT should be:
      """
      Success: Saved 'Foo' as revision 5 (1 replacement).
      Success: Changed 1 page.
      """
    And STDERR should contain:
      """
      Warning: Skipped 'Bar': it was edited after it was read.
      """
    When I run "mw page get Bar"
    Then STDOUT should be:
      """
      b
      """

  Scenario: A change that the pre-save transform undoes saves nothing
    When I run "echo a | mw page put Foo"
    And I run "mw search-replace '/a$/' 'a ' --namespace=0 --yes"
    Then STDERR should contain:
      """
      Warning: No change: 'Foo' would be saved with the same text.
      """
    And STDOUT should be:
      """
      Success: Changed 0 pages.
      """
