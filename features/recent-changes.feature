Feature: List recent changes

  Background:
    Given a wiki
    When I run "echo 'Hello' | mw maint edit --summary=Create Sandbox"
    And I run "echo 'Hello again' | mw maint edit --user=Admin --summary=Update Sandbox"
    And I run "echo 'Help' | mw maint edit --user=Admin Help:Intro"
    And I run "mw maint protect --reason=Vandalism Sandbox"

  Scenario: Recent changes are listed newest first
    When I run "mw recent-changes --fields=type,action,comment,title,user"
    Then STDOUT should be:
      """
      type	action	comment	title	user
      log	protect/protect	Vandalism	Sandbox	Maintenance script
      new			Help:Intro	Admin
      edit		Update	Sandbox	Admin
      new		Create	Sandbox	Maintenance script
      new			Main Page	MediaWiki default
      """
    When I run "mw recent-changes --limit=2 --format=ids"
    Then STDOUT should be:
      """
      5 4
      """
    When I run "mw recent-changes --limit=2 --format=csv --fields=id,title,namespace"
    Then STDOUT should be:
      """
      id,title,namespace
      5,Sandbox,0
      4,Help:Intro,12
      """
    When I run "mw recent-changes --limit=1 --format=yaml --fields=timestamp"
    Then STDOUT should contain:
      """
      timestamp: "20
      """
    And STDOUT should contain:
      """
      Z"
      """

  Scenario: Recent changes can be filtered
    When I run "mw recent-changes --by=admin --field=title"
    Then STDOUT should be:
      """
      Help:Intro
      Sandbox
      """
    When I run "mw recent-changes --type=edit --format=json --fields=title,revision"
    Then STDOUT should be:
      """
      [{"title":"Sandbox","revision":3}]
      """
    When I run "mw recent-changes --type=new --namespace=Help --field=title"
    Then STDOUT should be:
      """
      Help:Intro
      """

  Scenario: Hidden user names and edit summaries stay hidden
    When I run "mw maint sql --query='UPDATE /*_*/recentchanges SET rc_deleted = 6 WHERE rc_this_oldid = 3'"
    And I run "mw recent-changes --type=edit --format=json --fields=title,user,comment"
    Then STDOUT should be:
      """
      [{"title":"Sandbox","user":null,"comment":null}]
      """

  Scenario: Bad filters fail
    When I try "mw recent-changes --type=delete"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid type. Use one of: edit, new, log.
      """
    When I try "mw recent-changes --limit=0"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: --limit must be a positive number.
      """
