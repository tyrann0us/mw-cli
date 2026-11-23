Feature: List pages that link to or transclude a page

  Background:
    Given a wiki
    When I run "echo 'See [[Target]]. {{Greeting}}' | mw maint edit Linker"
    And I run "echo 'Also [[target]]' | mw maint edit Help:Linker"
    And I run "echo 'Nothing here' | mw maint edit Loner"
    And I run "mw maint runJobs --quiet"

  Scenario: Backlinks lists the linking pages
    When I run "mw backlinks Target"
    Then STDOUT should be:
      """
      id	title
      2	Linker
      3	Help:Linker
      """
    When I run "mw backlinks Target --namespace=help --format=json --fields=title,namespace"
    Then STDOUT should be:
      """
      [{"title":"Help:Linker","namespace":12}]
      """
    When I run "mw backlinks Target --namespace=0 --field=title"
    Then STDOUT should be:
      """
      Linker
      """
    When I run "mw backlinks Loner --format=count"
    Then STDOUT should be:
      """
      0
      """

  Scenario: Transclusions lists the pages that use a template
    When I run "mw transclusions Template:Greeting --format=ids"
    Then STDOUT should be:
      """
      2
      """
    When I run "mw transclusions Template:Greeting --namespace='Help talk' --format=count"
    Then STDOUT should be:
      """
      0
      """

  Scenario: Bad titles and namespaces fail
    When I try "mw backlinks '[[Target'"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Invalid title: [[Target.
      """
    When I try "mw transclusions Template:Greeting --namespace=Nope"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Unknown namespace: Nope.
      """
    When I try "mw backlinks Target --namespace=99"
    Then STDERR should contain:
      """
      Error: Unknown namespace: 99.
      """
