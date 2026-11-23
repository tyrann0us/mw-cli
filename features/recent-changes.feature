Feature: List recent changes

  Background:
    Given a wiki
    When I run "echo 'Hello' | mw maint edit --summary=Create Sandbox"
    And I run "echo 'Hello again' | mw maint edit --user=Admin --summary=Update Sandbox"
    And I run "echo 'Help' | mw maint edit --user=Admin Help:Intro"
    And I run "mw maint protect --reason=Vandalism Sandbox"

  Scenario: Recent changes are listed newest first
    When I run "mw recent-changes --limit=4 --fields=type,action,comment,title,user"
    Then STDOUT should be:
      """
      type	action	comment	title	user
      log	protect/protect	Vandalism	Sandbox	Maintenance script
      new			Help:Intro	Admin
      edit		Update	Sandbox	Admin
      new		Create	Sandbox	Maintenance script
      """
    When I run "mw recent-changes --limit=2 --format=csv --fields=title,namespace"
    Then STDOUT should be:
      """
      title,namespace
      Sandbox,0
      Help:Intro,12
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
    When I run "mw recent-changes --type=edit --format=json --fields=title,type"
    Then STDOUT should be:
      """
      [{"title":"Sandbox","type":"edit"}]
      """
    When I run "mw recent-changes --type=new --namespace=Help --field=title"
    Then STDOUT should be:
      """
      Help:Intro
      """

  Scenario: Hidden user names, edit summaries and log actions stay hidden
    When I run "mw maint sql --query='UPDATE /*_*/recentchanges SET rc_deleted = 6 WHERE rc_this_oldid = (SELECT m FROM (SELECT MAX(rc_this_oldid) AS m FROM /*_*/recentchanges WHERE rc_last_oldid > 0 AND rc_log_type IS NULL) AS t)'"
    And I run "mw maint sql --query='UPDATE /*_*/recentchanges SET rc_deleted = 1 WHERE rc_log_type IS NOT NULL'"
    And I run "mw recent-changes --limit=3 --format=json --fields=type,title,namespace,action,user,comment"
    Then STDOUT should be:
      """
      [{"type":"log","title":null,"namespace":null,"action":null,"user":"Maintenance script","comment":"Vandalism"},{"type":"new","title":"Help:Intro","namespace":12,"action":null,"user":"Admin","comment":""},{"type":"edit","title":"Sandbox","namespace":0,"action":null,"user":null,"comment":null}]
      """
    When I run "mw recent-changes --limit=1 --format=json --fields=type,revision"
    Then STDOUT should be:
      """
      [{"type":"log","revision":null}]
      """
    When I run "mw recent-changes --by=Admin --field=title"
    Then STDOUT should be:
      """
      Help:Intro
      """
    When I run "mw recent-changes --namespace=0 --field=type"
    Then STDOUT should be:
      """
      edit
      new
      new
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
    When I try "mw recent-changes --by"
    Then STDERR should contain:
      """
      Error: --by needs a value.
      """
