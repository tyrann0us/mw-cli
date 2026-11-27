Feature: Run mw, get help and use global flags

  Scenario: Help lists the commands
    When I run "mw help"
    Then STDOUT should contain:
      """
      Runs a MediaWiki maintenance script.
      """

  Scenario: Help opens in a pager on a terminal
    When I run "script -qec 'PAGER=rev mw help cli version' /dev/null"
    Then STDOUT should contain:
      """
      EMAN
      """

  Scenario: Help is printed when the pager is missing
    When I run "script -qec 'PAGER=no-such-pager mw help cli version' /dev/null"
    Then STDOUT should contain:
      """
      NAME
      """

  Scenario: Help for a subcommand shows its usage
    When I run "mw cli version --help"
    Then STDOUT should contain:
      """
      mw cli version
      """

  Scenario: A command group without subcommand lists its subcommands
    When I run "mw cli"
    Then STDOUT should be:
      """
      usage: mw cli info [--format=<format>] [--fields=<fields>] [--field=<field>]
         or: mw cli version

      See 'mw help cli <command>' for more information on a specific command.
      """
    When I run "mw help cli"
    Then STDOUT should contain:
      """
        version  Prints the mw version.
      """

  Scenario: Subcommands of several words are typed and shown as separate words
    When I run "mw help page category list"
    Then STDOUT should contain:
      """
      mw page category list <title> [--limit=<n>]
      """
    When I run "mw page category list --help"
    Then STDOUT should contain:
      """
      Lists the categories of a page.
      """
    When I run "mw page category"
    Then STDOUT should contain:
      """
      usage: mw page category list <title>
      """
    When I run "mw help page category"
    Then STDOUT should contain:
      """
      SYNOPSIS

        mw page category <command>

      SUBCOMMANDS

        list  Lists the categories of a page.
      """
    When I run "mw page"
    Then STDOUT should contain:
      """
      usage: mw page category list <title>
      """
    When I run "mw help page"
    Then STDOUT should contain:
      """
        category list  Lists the categories of a page.
      """
    When I try "mw page category nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: 'nope' is not a registered subcommand of 'page category'. See 'mw help page category' for available subcommands.
      """
    When I try "mw page category-list Main_Page"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: 'category-list' is not a registered subcommand of 'page'. See 'mw help page' for available subcommands.
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
      Error: 'nope' is not a registered mw command. See 'mw help' for available commands.
      """
    When I try "mw pgae list"
    Then the return code should be 1
    And STDERR should contain:
      """
      See 'mw help' for available commands.
      Did you mean 'page'?
      """
    When I try "mw page lsit"
    Then STDERR should contain:
      """
      Did you mean 'list'?
      """
    When I try "mw cli nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: 'nope' is not a registered subcommand of 'cli'. See 'mw help cli' for available subcommands.
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

  Scenario: Maintenance script errors use mw's wording
    When I try "mw maint noSuchScript"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown maintenance script 'noSuchScript'.
      """
    When I try "mw maint showSiteStats --nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown parameter: --nope.
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
