# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mw` is a command-line tool for MediaWiki. It runs on the host where MediaWiki is installed and loads MediaWiki in-process, so commands use MediaWiki's PHP services, not the web API. It can also run a command on a remote host over SSH (`--ssh=` or an `@alias`). In that case it calls the `mw` installed on the remote host.

Targets MediaWiki 1.43+ (LTS) and PHP 8.1+ (MediaWiki 1.43's minimum).

## Commands

```sh
composer install                      # dev deps only (PHPUnit, Behat); runtime has none
composer test                         # unit + functional
vendor/bin/phpunit                    # unit tests
vendor/bin/phpunit --filter ConfigTest::testAliasOverridesPath   # single unit test
composer lint                         # php -l + phpcs (mediawiki/mediawiki-codesniffer)

docker compose up -d                  # test wiki: MediaWiki 1.43 + SQLite (+ SMW, + sshd)
vendor/bin/behat                      # functional tests against the test wiki
vendor/bin/behat features/page.feature:12   # single scenario by line
vendor/bin/behat --tags='~@smw'       # skip scenarios that need Semantic MediaWiki

bin/mw --path=/var/www/w site info    # run from a checkout
```

## Architecture

Request flow for `mw [@alias] [global flags] <command> <subcommand> [args] [--assoc=args]`:

1. **`bin/mw`** parses global flags, then loads config: `mw.ini` (searched upward from cwd) is merged over `~/.mw/config.ini`. Alias sections are INI sections named `[@prod]`. Config is read before MediaWiki loads, which is why it's INI (`parse_ini_file`) rather than YAML.
2. **Remote dispatch** (`src/Runner.php`): if `--ssh` is set, either directly or through an alias, the runner removes the SSH flags, rebuilds argv and runs `ssh -T [user@]host[:port] 'cd <path> && mw …'` with `passthru`, then exits with the remote exit code. Nothing below this step runs locally.
3. **Bootstrap** (`src/Bootstrap.php`) finds the MediaWiki root (`--path`, otherwise walk up from cwd looking for `LocalSettings.php` next to `includes/`). It then loads MediaWiki through its own maintenance entry point (`maintenance/Maintenance.php` + `RUN_MAINTENANCE_IF_MAIN`). This gives the same environment `php maintenance/run.php` gets: settings, services, extensions. `--user=<name>` sets the acting user for writes.
4. **Dispatch**: commands are classes in `src/Command/` extending `MwCli\Command`, found by file name (`SearchReplace.php` is `mw search-replace`). Subcommands are public methods with the signature `(array $args, array $assoc_args)`; a class with `__invoke()` has no subcommands. Help text is the method docblock (`## OPTIONS` with tokens like `<title>`, `[--format=<format>]`, then `## EXAMPLES`). `src/Synopsis.php` parses it: `mw help` prints it, and the runner rejects missing args and unknown options against it. Commands that don't load MediaWiki set `NEEDS_WIKI = false`.
5. **Output** goes through `src/Formatter.php` for every list or record: `--format=table|json|csv|yaml|ids|count` and `--fields=`. Commands return rows and never echo them directly. Messages go through `Runner::success/warning/error()`. `error()` writes to stderr and exits 1.

Constraints that follow from loading MediaWiki in the same process:

- **No runtime Composer dependencies.** Our autoloader runs next to MediaWiki's `vendor/`. Any library we ship could conflict with the version MediaWiki or an extension already loaded. Use the PHP stdlib, or MediaWiki's own classes after bootstrap.
- Call MediaWiki only through `MediaWikiServices` (`getWikiPageFactory()`, `getPageUpdaterFactory()`, `getMovePageFactory()`, …). Avoid deprecated globals and static helpers, because they break between 1.43 and the next LTS.
- Writes go through `PageUpdater` with an explicit actor and edit summary, so they show up in page history and recent changes like normal edits.

MediaWiki-specific commands:

- `ask`: Semantic MediaWiki queries. It fails with a clear error if SMW isn't loaded.
- `parse`: wikitext to HTML.
- `maint <script> [args]`: passthrough to `maintenance/run.php`, which covers the job queue, rebuilds and imports.

`mw search-replace <regex> <replacement>` is scoped with `--category=` or `--namespace=`. `--dry-run` prints a unified diff per page without saving.

## Tests

Tests come in two layers:

- **`tests/unit/`** (PHPUnit): logic that doesn't need MediaWiki, i.e. argv and global flag parsing, config/alias merging, building the SSH command line, and the formatter.
- **`features/`** (Behat): one `.feature` per command group, run against the dockerized test wiki. Steps read like `Given a wiki`, `When I run "mw page get Main_Page"`, `Then STDOUT should contain:`, `Then the return code should be 1`. The step definitions are in `features/bootstrap/FeatureContext.php`. Each scenario resets the wiki from a SQLite snapshot, so scenarios are independent.
- Scenarios tagged `@smw` need Semantic MediaWiki. Scenarios tagged `@ssh` run against the compose `sshd` service, which has `mw` installed.

CI (`.github/workflows/test.yml`) runs lint and unit tests on PHP 8.1 to 8.4. It runs Behat against MediaWiki 1.43, once with SMW and once without.

## Roadmap

Commands that are out of MVP scope (upload, XML export/import, `user`, command packages/extensibility, phar build) are tracked in `ROADMAP.md`. Don't build them unless asked.

## Workflow

- Commit messages follow Conventional Commits (`feat(page): add move subcommand`). Keep the body empty or one or two lines. The full explanation goes in the PR description.
- Before opening a PR, run `/ponytail:ponytail-review`, `/code-review` and `/security-review` on the diff. Run prose (README, `--help` text, PR descriptions) through `/humanizer:humanizer`.
