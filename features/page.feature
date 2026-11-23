Feature: Read, edit and manage pages

  Scenario: Get and put page text
    Given a wiki
    And a file "/tmp/foo.wiki" with:
      """
      Hello [[world]]
      """
    When I run "mw page get Main_Page"
    Then STDOUT should contain:
      """
      MediaWiki has been installed.
      """
    When I run "mw page put Foo /tmp/foo.wiki --summary='First version'"
    Then STDOUT should be:
      """
      Success: Saved 'Foo' as revision 2.
      """
    When I run "mw page get Foo"
    Then STDOUT should be:
      """
      Hello [[world]]
      """
    When I run "mw page put Foo /tmp/foo.wiki"
    Then STDERR should contain:
      """
      Warning: No change: 'Foo' already has this text.
      """
    When I run "echo 'Second version' | mw page put Foo --minor"
    Then STDOUT should be:
      """
      Success: Saved 'Foo' as revision 3.
      """
    When I run "mw maint sql --query='SELECT actor_name, comment_text, rev_minor_edit FROM revision JOIN actor ON rev_actor = actor_id JOIN comment ON rev_comment_id = comment_id WHERE rev_id = 2'"
    Then STDOUT should contain:
      """
          [actor_name] => Maintenance script
          [comment_text] => First version
          [rev_minor_edit] => 0
      """

  Scenario: Put checks its input before saving
    When I try "mw page get Nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Page 'Nope' doesn't exist.
      """
    When I try "mw page get Special:Version"
    Then STDERR should contain:
      """
      Error: Invalid page title 'Special:Version'.
      """
    When I try "mw page put Foo /tmp/nope.wiki"
    Then STDERR should contain:
      """
      Error: Can't read '/tmp/nope.wiki'.
      """
    When I try "echo x | mw page put Main_Page --create-only"
    Then STDERR should contain:
      """
      Error: Page 'Main Page' already exists.
      """
    When I try "echo x | mw page put Foo --no-create"
    Then STDERR should contain:
      """
      Error: Page 'Foo' doesn't exist.
      """
    When I try "echo '{' | mw page put MediaWiki:Foo.json"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error:
      """
    When I try "mw page list --namespace=0 --format=count"
    Then STDOUT should be:
      """
      1
      """

  Scenario: Edits are made as the user from --user
    When I run "echo One | mw --user=Admin page put Foo"
    And I run "mw maint sql --query='SELECT comment_text FROM revision JOIN comment ON rev_comment_id = comment_id WHERE rev_id = 2'"
    Then STDOUT should contain:
      """
          [comment_text] => Created page with "One
      """
    When I run "echo Two | mw --user=1 page put Foo"
    And I run "echo Three | mw --quiet page put Foo --summary=Quiet"
    Then STDOUT should be:
      """
      """
    When I run "mw maint sql --query='SELECT actor_name, actor_user FROM revision JOIN actor ON rev_actor = actor_id WHERE rev_id > 1 ORDER BY rev_id'"
    Then STDOUT should contain:
      """
          [actor_name] => Admin
      """
    And STDOUT should contain:
      """
          [actor_user] => 1
      )
      stdClass Object
      (
          [actor_name] => Maintenance script
      """
    When I try "echo x | mw --user=Nobody page put Foo"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid user 'Nobody'.
      """
    When I try "echo x | mw --user=999 page put Foo"
    Then STDERR should contain:
      """
      Error: Invalid user '999'.
      """

  Scenario: List pages by namespace and prefix
    When I run "echo a | mw page put Foo && echo b | mw page put Foo/Bar && echo c | mw page put Help:Foo"
    And I run "mw page list --fields=title,namespace,redirect --format=csv"
    Then STDOUT should be:
      """
      title,namespace,redirect
      Foo,0,false
      Foo/Bar,0,false
      "Main Page",0,false
      Help:Foo,12,false
      """
    When I run "mw page list --prefix=foo --field=title"
    Then STDOUT should be:
      """
      Foo
      Foo/Bar
      Help:Foo
      """
    When I run "mw page list --namespace='Help talk' --format=count"
    Then STDOUT should be:
      """
      0
      """
    When I run "mw page list --namespace=Help --format=ids"
    Then STDOUT should be:
      """
      4
      """
    When I run "mw page list --namespace=0 --prefix=Foo/ --field=title"
    Then STDOUT should be:
      """
      Foo/Bar
      """
    When I try "mw page list --namespace=Nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown namespace 'Nope'.
      """

  Scenario: Delete asks for confirmation
    When I run "echo a | mw page put Foo"
    And I try "mw page delete Foo"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: No answer on stdin. Pass --yes to run without confirmation.
      """
    When I run "echo n | mw page delete Foo Main_Page"
    Then STDERR should contain:
      """
      Delete 2 pages? [y/n]
      """
    When I run "echo y | mw page delete Foo --reason=Cleanup"
    Then STDOUT should be:
      """
      Success: Deleted 'Foo'.
      """
    When I try "mw page get Foo"
    Then the return code should be 1

  Scenario: Delete checks all pages before deleting any
    When I try "mw page delete Main_Page Nope Nope2 --yes"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Page not found: 'Nope', 'Nope2'.
      """
    When I run "mw page delete Main_Page main_Page --yes"
    Then STDOUT should be:
      """
      Success: Deleted 'Main Page'.
      """

  Scenario: Move a page with or without a redirect
    When I run "echo text | mw page put Foo"
    And I run "mw page move Foo Bar --reason=Rename"
    Then STDOUT should be:
      """
      Success: Moved 'Foo' to 'Bar'.
      """
    When I run "mw page get Foo"
    Then STDOUT should be:
      """
      #REDIRECT [[Bar]]
      """
    When I run "mw page move Bar Help:Bar --no-redirect"
    And I try "mw page get Bar"
    Then the return code should be 1
    When I try "mw page move Help:Bar Main_Page"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error:
      """
    When I run "mw page get Help:Bar"
    Then STDOUT should be:
      """
      text
      """

  Scenario: Protect a page
    When I run "mw page protect Main_Page --edit=sysop --reason=Busy"
    Then STDOUT should be:
      """
      Success: Protected 'Main Page': edit=sysop, move=all.
      """
    When I run "mw page protect Main_Page --move=autoconfirmed --expiry='1 week'"
    Then STDOUT should be:
      """
      Success: Protected 'Main Page': edit=sysop, move=autoconfirmed.
      """
    When I run "mw page protect Main_Page --edit=all"
    Then STDOUT should be:
      """
      Success: Protected 'Main Page': edit=all, move=autoconfirmed.
      """
    When I run "mw maint sql --query='SELECT pr_type, pr_level, pr_expiry IS NULL AS forever FROM page_restrictions'"
    Then STDOUT should contain:
      """
          [pr_type] => move
          [pr_level] => autoconfirmed
          [forever] => 0
      """

  Scenario: Protect checks its options
    When I try "mw page protect Main_Page"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Pass --edit or --move.
      """
    When I try "mw page protect Main_Page --edit=nope"
    Then STDERR should contain:
      """
      Error: Invalid --edit level 'nope'. Use all, autoconfirmed, sysop.
      """
    When I try "mw page protect Main_Page --edit=sysop --expiry=yesterday"
    Then STDERR should contain:
      """
      Error: Invalid expiry 'yesterday'. Use a future time like '1 week' or 'infinite'.
      """
    When I try "mw page protect Nope --edit=sysop"
    Then STDERR should contain:
      """
      Error: Page not found: 'Nope'.
      """

  Scenario: Purge pages
    When I run "mw page purge Main_Page"
    Then STDOUT should be:
      """
      Success: Purged 'Main Page'.
      """

  @ssh
  Scenario: An SSH alias's user edits on the remote wiki
    Given a file "/tmp/project/mw.ini" with:
      """
      [@prod]
      ssh = root@localhost
      path = /var/www/html
      user = Admin
      """
    When I run "cd /tmp/project && echo text | mw @prod page put Foo"
    Then STDOUT should be:
      """
      Success: Saved 'Foo' as revision 2.
      """
    When I run "mw maint sql --query='SELECT actor_name FROM revision JOIN actor ON rev_actor = actor_id WHERE rev_id = 2'"
    Then STDOUT should contain:
      """
          [actor_name] => Admin
      """
