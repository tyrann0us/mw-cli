Feature: Manage user accounts

  Background:
    Given a wiki

  Scenario: List and show users
    When I run "mw user list --fields=name,groups"
    Then STDOUT should contain:
      """
      Admin	bureaucrat, interface-admin, sysop
      """
    When I run "mw user list --group=sysop --field=name"
    Then STDOUT should be:
      """
      Admin
      """
    When I run "mw user create 'Bot one'"
    And I run "mw user list --prefix=bot_ --field=name"
    Then STDOUT should be:
      """
      Bot one
      """
    When I run "mw user list --prefix=adm --format=count"
    Then STDOUT should be:
      """
      1
      """
    When I run "mw user list --prefix=Nobody --format=json"
    Then STDOUT should be:
      """
      []
      """
    When I run "mw user get Admin --fields=name,groups --format=json"
    Then STDOUT should be:
      """
      {"name":"Admin","groups":"bureaucrat, interface-admin, sysop"}
      """
    # The ID of Admin differs between MediaWiki versions, so the lookup by ID checks the ID.
    When I run "mw user get 1 --field=id"
    Then STDOUT should be:
      """
      1
      """

  Scenario: Unknown users and groups fail
    When I try "mw user get Nobody"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'Nobody' doesn't exist.
      """
    When I try "mw user list --group=nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown group 'nope'. Use one of: bot, bureaucrat,
      """

  Scenario: Create users
    When I run "mw user create Alice alice@example.org"
    Then STDOUT should be like:
      """
      Success: Created user 'Alice' (ID {id}).
      """
    When I run "mw user get Alice --field=email"
    Then STDOUT should be:
      """
      alice@example.org
      """
    When I run "printf 'Secr3t-Passw0rd' | mw user create Bob --password --porcelain"
    Then STDOUT should be like:
      """
      {id}
      """
    When I run "mw user get Bob --field=name"
    Then STDOUT should be:
      """
      Bob
      """

  Scenario: Creating a user checks everything first
    When I try "mw user create Admin"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'Admin' already exists.
      """
    When I try "mw user create 'Bad|Name'"
    Then STDERR should contain:
      """
      Error: Invalid user name 'Bad|Name'.
      """
    When I try "mw user create Carl nope"
    Then STDERR should contain:
      """
      Error: Invalid email address 'nope'.
      """
    When I try "printf '' | mw user create Carl --password"
    Then STDERR should contain:
      """
      Error: Invalid password: empty.
      """
    When I try "printf 'x' | mw user create Carl --password"
    Then STDERR should contain:
      """
      Error: Invalid password: Passwords must be at least 8 characters.
      """
    When I try "script -qec 'mw user create Carl --password' /dev/null"
    Then STDOUT should contain:
      """
      --password reads the password from stdin. Pipe it in.
      """
    When I try "mw user get Carl"
    Then the return code should be 1

  Scenario: Add users to groups and remove them
    When I run "mw user create Alice"
    And I run "mw user add-group Alice sysop bot --reason=Trusted"
    Then STDOUT should be:
      """
      Success: Added user 'Alice' to groups 'sysop', 'bot'.
      """
    When I run "mw user add-group Alice sysop"
    Then STDERR should contain:
      """
      Warning: User 'Alice' is already in group 'sysop'.
      """
    When I run "mw user remove-group Alice bot"
    Then STDOUT should be:
      """
      Success: Removed user 'Alice' from group 'bot'.
      """
    When I run "mw user remove-group Alice bot"
    Then STDERR should contain:
      """
      Warning: User 'Alice' isn't in group 'bot'.
      """
    When I run "mw user get Alice --field=groups"
    Then STDOUT should be:
      """
      sysop
      """
    When I run "mw recent-changes --type=log --fields=title,action,comment --limit=2"
    Then STDOUT should contain:
      """
      User:Alice	rights/rights	Trusted
      """
    When I try "mw --user=Nobody user add-group Alice bot"
    Then the return code should be 1
    When I run "mw user get Alice --field=groups"
    Then STDOUT should be:
      """
      sysop
      """
    When I try "mw user add-group Alice nope sysop other"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown groups 'nope', 'other'. Use one of:
      """
    When I try "mw user add-group Nobody sysop"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'Nobody' doesn't exist.
      """

  Scenario: Update a user
    When I run "mw user create Alice"
    And I run "mw user update Alice --email=alice@example.org --real-name='Alice Example'"
    Then STDOUT should be:
      """
      Success: Updated user 'Alice'.
      """
    When I run "mw user get Alice --fields=email,realname --format=json"
    Then STDOUT should be:
      """
      {"email":"alice@example.org","realname":"Alice Example"}
      """
    When I run "printf 'N3w-Passw0rd-2' | mw user update Alice --password --email="
    And I run "mw user get Alice --field=email"
    Then STDOUT should be:
      """

      """
    When I try "printf 'N3w-Passw0rd-2' | mw user update 'Maintenance script' --password"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'Maintenance script' is a system user and can't have a password.
      """
    When I try "mw user update Alice --no-password"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Pass --email, --real-name or --password.
      """
    When I try "mw user update Alice --email=nope"
    Then STDERR should contain:
      """
      Error: Invalid email address 'nope'.
      """

  Scenario: Set a user's groups and list their rights
    When I run "mw user create Alice"
    And I run "mw user set-group Alice sysop bot"
    Then STDOUT should be:
      """
      Success: Set the groups of user 'Alice' to 'bot', 'sysop'.
      """
    When I run "mw user set-group Alice sysop bot"
    Then STDERR should contain:
      """
      Warning: User 'Alice' already has exactly these groups.
      """
    When I run "mw user list-rights Alice --format=json"
    Then STDOUT should contain:
      """
      {"right":"delete"}
      """
    When I run "mw user list-rights Alice --limit=2 --format=count"
    Then STDOUT should be:
      """
      2
      """
    When I run "mw user list --group=bot --fields='name, groups'"
    Then STDOUT should contain:
      """
      Alice	bot, sysop
      """
    When I run "mw user set-group Alice"
    Then STDOUT should be:
      """
      Success: Set the groups of user 'Alice' to none.
      """
    When I run "mw user get Alice --field=groups"
    Then STDOUT should be:
      """

      """

  Scenario: Delete unused accounts only
    When I run "mw user create Alice"
    And I run "mw user create Bob"
    And I run "echo Hi | mw --user=Bob page put Sandbox"
    When I try "mw user delete Alice"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: No answer on stdin. Pass --yes to run without confirmation.
      """
    When I try "mw user delete Alice Bob --yes"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'Bob' has edits, uploads or log entries, so it can't be deleted. Block it instead.
      """
    When I run "mw user create Carl"
    And I run "echo Carl | mw maint blockUsers --reason=Spam"
    And I try "mw user delete Carl --yes"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'Carl' is blocked, so it can't be deleted.
      """
    # An account that registered itself has its own creation entry, which goes with it.
    Given a file "/tmp/self.sql" with:
      """
      UPDATE logging SET log_actor = (SELECT actor_id FROM actor WHERE actor_name = 'Alice') WHERE log_type = 'newusers' AND log_title = 'Alice';
      """
    When I try "mw user delete 'MediaWiki default' --yes"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: User 'MediaWiki default' is a system user and can't be deleted.
      """
    When I run "mw maint sql /tmp/self.sql"
    And I run "mw user delete Alice --yes"
    Then STDOUT should be:
      """
      Success: Deleted user 'Alice'.
      """
    Given a file "/tmp/left.sql" with:
      """
      SELECT COUNT(*) AS n FROM logging WHERE log_type = 'newusers' AND log_title = 'Alice';
      """
    When I run "mw maint sql /tmp/left.sql"
    Then STDOUT should contain:
      """
      [n] => 0
      """
    Given a file "/tmp/fred.sql" with:
      """
      SELECT COUNT(*) AS n FROM logging WHERE log_type = 'newusers' AND log_title = 'Fred';
      """
    When I run "mw user create Fred"
    And I run "mw user delete Fred --yes"
    And I run "mw maint sql /tmp/fred.sql"
    Then STDOUT should contain:
      """
      [n] => 0
      """
    When I run "mw user create Dora"
    And I run "mw --user=Dora user create Eve"
    And I run "mw user delete Dora --yes"
    Then STDOUT should be:
      """
      Success: Deleted user 'Dora'.
      """
    Given a file "/tmp/q.sql" with:
      """
      SELECT log_title FROM logging WHERE log_type = 'newusers' AND log_action = 'create2';
      """
    When I run "mw maint sql /tmp/q.sql"
    Then STDOUT should contain:
      """
      Eve
      """
    When I try "mw user get Alice"
    Then the return code should be 1
    When I run "mw user get Bob --field=name"
    Then STDOUT should be:
      """
      Bob
      """
