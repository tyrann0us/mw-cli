Feature: Run mw, get help and use global flags

  Scenario: Help lists the commands
    When I run "mw help"
    Then STDOUT should contain:
      """
      Runs a MediaWiki maintenance script.
      """

  Scenario: Help for a subcommand shows its usage
    When I run "mw cli version --help"
    Then STDOUT should contain:
      """
      mw cli version
      """

  Scenario: A command group without subcommand lists its subcommands
    When I run "mw cli"
    Then STDOUT should contain:
      """
      version  Prints the mw version.
      """

  Scenario: Show the mw version and environment
    When I run "mw cli version"
    Then STDOUT should be:
      """
      mw 0.1.0
      """
    When I run "mw cli info --field=mw_cli_dir"
    Then STDOUT should be:
      """
      /mw-cli
      """
    When I run "mw cli info"
    Then STDOUT should contain:
      """
      mw_cli_version	0.1.0
      """

  Scenario: Unknown commands and parameters fail
    When I try "mw nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: 'nope' is not a registered mw command. See 'mw help'.
      """
    When I try "mw cli nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: 'nope' is not a registered subcommand of 'cli'. See 'mw help cli'.
      """
    When I try "mw cli version --nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown parameter: --nope.
      """

  Scenario: mw finds the wiki from a subdirectory or --path
    When I run "cd /var/www/html/maintenance && mw maint showSiteStats"
    Then STDOUT should contain:
      """
      Total pages       : 1
      """
    When I try "cd /tmp && mw maint showSiteStats"
    Then the return code should be 1
    And STDERR should contain:
      """
      This does not seem to be a MediaWiki installation.
      """
    When I run "cd /tmp && mw --path=/var/www/html maint showSiteStats"
    Then STDOUT should contain:
      """
      Total pages       : 1
      """

  Scenario: Aliases from mw.ini
    Given a file "/tmp/project/mw.ini" with:
      """
      [@local]
      path = /var/www/html
      """
    When I run "cd /tmp/project && mw @local maint showSiteStats"
    Then STDOUT should contain:
      """
      Total pages       : 1
      """
    When I try "cd /tmp/project && mw @nope cli version"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Alias '@nope' not found.
      """

  @ssh
  Scenario: Run a command over SSH
    When I run "cd /tmp && mw --ssh=root@localhost/var/www/html maint showSiteStats"
    Then STDOUT should contain:
      """
      Total pages       : 1
      """

  @ssh
  Scenario: A RemoteCommand in the SSH config doesn't stop mw
    When I run "mw --ssh=root@remotecmd/var/www/html site info --field=name"
    Then STDOUT should be:
      """
      Test Wiki
      """

  @ssh
  Scenario: Run a command through an SSH alias
    Given a file "/tmp/project/mw.ini" with:
      """
      [@prod]
      ssh = root@localhost
      path = /var/www/html
      """
    When I run "cd /tmp/project && mw @prod maint showSiteStats"
    Then STDOUT should contain:
      """
      Total pages       : 1
      """

  Scenario: Maintenance scripts get their arguments and exit code
    When I run "mw maint getText 'Main Page'"
    Then STDOUT should contain:
      """
      MediaWiki has been installed.
      """
    When I try "mw maint getText -- --help"
    Then STDOUT should contain:
      """
      Outputs page text to stdout
      """
    When I try "mw maint doesNotExist"
    Then the return code should be 1
