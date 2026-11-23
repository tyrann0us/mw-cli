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
vendor/bin/phpunit --filter ConfigTest::testUnknownAliasFails   # single unit test
composer lint                         # php -l + phpcs (mediawiki/mediawiki-codesniffer)
composer phpstan                      # level 8 on src/ and bin/mw, once against MediaWiki 1.43 and once against 1.46

docker compose up -d --build --wait  # test wiki: MediaWiki 1.43 + SQLite + sshd
# Variants (docker compose down first): MW=1.46, MW=1.46 MW_BRANCH=master, SMW=1,
# DB=mariadb COMPOSE_PROFILES=mariadb, COVERAGE=1 (then php tests/coverage/clover.php)

# Unit coverage with the container's pcov (the host may have no coverage driver):
docker compose exec -T -w /mw-cli wiki php -d pcov.enabled=1 -d pcov.directory=/mw-cli/src \
  -d auto_prepend_file= vendor/bin/phpunit --coverage-clover build/unit-clover.xml
vendor/bin/behat                      # functional tests against the test wiki
vendor/bin/behat features/cli.feature:12    # single scenario by line
vendor/bin/behat --tags='~@smw'       # skip scenarios that need Semantic MediaWiki

bin/mw --path=/var/www/w cli info     # run from a checkout
```

## Architecture

Request flow for `mw [@alias] [global flags] <command> <subcommand> [args] [--assoc=args]`:

1. `bin/mw` registers a tiny autoloader for `MwCli\` and creates `Runner`, which splits global flags (`--path`, `--ssh`, `--user`, `--quiet`) from command options and loads config (`src/Config.php`): `mw.ini` (searched upward from cwd) is merged over `~/.mw/config.ini`. Alias sections are INI sections named `[@prod]`. Config is read before MediaWiki loads, which is why it's INI (`parse_ini_file`) rather than YAML.
2. Remote dispatch (`Runner::sshCommand()`): if `--ssh` is set, directly or through an alias, `bin/mw` runs `ssh -T [-p port] -- [user@]host 'mw --path=<path> …'` with `passthru` and exits with the remote exit code. The original argv is forwarded minus `--ssh` and the alias. An alias with `ssh` keeps its `path`/`user` for the remote side (`remote-path`/`remote-user`). Nothing below this step runs locally.
3. Bootstrap: `Runner::wikiPath()` finds the MediaWiki root (`--path`, otherwise walk up from cwd until `maintenance/run.php` exists) and rejects versions below 1.43. `src/bootstrap.php` then does what `maintenance/run.php` does, but runs `MwCli\MwMaintenance` through `MaintenanceRunner::initForClass()`. It must be required at file scope because `Setup.php` needs global scope. `MwMaintenance::execute()` calls back into `Runner::run()`, so commands get settings, services, extensions and the DB, and MediaWiki commits and runs deferred updates afterwards.
4. Dispatch: commands are classes in `src/Command/` extending `MwCli\Command`, found by file name (`SearchReplace.php` is `mw search-replace`). Subcommands are public methods with the signature `(array $args, array $assoc_args)`; a class with `__invoke()` has no subcommands. Help text is the method docblock (`## OPTIONS` with tokens like `<title>`, `[--format=<format>]` or `[--no-redirect]`, then `## EXAMPLES`), up to the first `@`-tag; the `@param string[] $args` / `@param array<string,string|bool> $assoc_args` lines after it are for PHPStan. `src/Synopsis.php` parses it: `mw help` prints it, and `Runner::validate()` rejects missing args and unknown options against it before MediaWiki loads. Commands that don't load MediaWiki set `NEEDS_WIKI = false`.
5. Output goes through `Formatter::items()` (lists) or `Formatter::item()` (one record): `--format=table|json|csv|yaml|ids|count`, `--fields=` and `--field=`. Tables are tab-separated when stdout isn't a TTY, which is what tests see. Messages go through `Runner::line()`, `Runner::success()` and `Runner::warning()`. `Runner::error()` throws `ExitException`; the runner prints it to stderr and exits 1. Never call `exit` in a command: it would skip MediaWiki's commit. MediaWiki commits even when a command ends with `Runner::error()`, so check everything before the first write. Commands that pass arguments on unchanged (like `maint`) use `Runner::rawArgs()`.

Constraints that follow from loading MediaWiki in the same process:

- No runtime Composer dependencies: our autoloader runs next to MediaWiki's `vendor/`. Any library we ship could conflict with the version MediaWiki or an extension already loaded. Use the PHP stdlib, or MediaWiki's own classes after bootstrap.
- Call MediaWiki only through `MediaWikiServices` (`getWikiPageFactory()`, `getPageUpdaterFactory()`, `getMovePageFactory()`, …). Avoid deprecated globals and static helpers, because they break between 1.43 and the next LTS.
- Writes go through `PageUpdater` with an explicit actor and edit summary, so they show up in page history and recent changes like normal edits.

Coverage must stay at 100%, so helpers are added together with their first caller.

Write commands take the performer from `Command::performer()`: the global `--user` value as a name, or as an ID if no user has that name, otherwise `User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] )`. An SSH alias's `user` is forwarded to the remote side as `--user`. `Runner::success()` prints `Success: …` on stdout and `Runner::warning()` prints `Warning: …` on stderr; the global `--quiet` flag silences both. `Runner::confirm()` asks unless `--yes` is passed, fails when stdin has no answer, and on any answer but `y` ends the command with exit code 0 before anything changes.

MediaWiki-specific commands:

- `ask`: Semantic MediaWiki queries. It fails with a clear error if SMW isn't loaded.
- `parse`: wikitext to HTML.
- `maint <script> [args]`: passthrough to `maintenance/run.php`, which covers the job queue, rebuilds and imports.

`mw search-replace <regex> <replacement>` is scoped with `--category=` or `--namespace=`, and searches the content namespaces without either. It reads and checks every page before the first save. A page edited in between is skipped with a warning (`PageUpdater::hasEditConflict()`). `--dry-run` prints a unified diff per page (MediaWiki's `Wikimedia\Diff\UnifiedDiffFormatter`) without saving.

## Tests

Tests come in two layers:

- `tests/unit/` (PHPUnit): logic that doesn't need MediaWiki, i.e. argv and global flag parsing, config/alias merging, building the SSH command line, and the formatter.
- `features/` (Behat): one `.feature` per command group, run against the dockerized test wiki. Steps read like `Given a wiki`, `When I run "mw page get Main_Page"`, `Then STDOUT should contain:`, `Then the return code should be 1`. The step definitions are in `features/bootstrap/FeatureContext.php`. Each scenario resets the wiki from a snapshot (`reset-wiki`), so scenarios are independent. SMW's install adds its own pages and revisions, so page, revision and change IDs differ between test wikis: `Then STDOUT should be like:` compares exactly but takes `{id}` for any ID, and SQL checks select pages by title. Run new scenarios with `SMW=1` as well, since that job uploads the Behat coverage.
- Scenarios tagged `@smw` need Semantic MediaWiki. Scenarios tagged `@ssh` run `mw --ssh=root@localhost…` inside the container, which runs sshd and trusts its own key. Commands in steps run via `docker compose exec` in `/var/www/html` (override with `MW_CLI_EXEC`).

The container installs the wiki on first start (`tests/docker/setup.sh`) and snapshots the database; `reset-wiki` restores it before each scenario. With `COVERAGE=1`, `tests/coverage/prepend.php` is prepended to every PHP process in the container and writes raw pcov data to `build/coverage/`.

CI uses inpsyde/reusable-workflows where one exists. `quality-assurance-php.yml` runs lint, phpcs, PHPStan (against MediaWiki 1.43 and 1.46) and PHPUnit on PHP 8.1 to 8.4, with unit coverage going to Codecov. `functional-tests.yml` calls `behat.yml` for MediaWiki 1.43 and 1.46 on SQLite and MariaDB, 1.43 with SMW (this job also uploads Behat coverage), and MediaWiki master in the daily cron only. `lint.yml` runs actionlint, gherkin-lint and typos. `.codecov.yml` requires 100% coverage, combining unit and Behat coverage. `composer.lock` is committed and resolved for PHP 8.1 (`config.platform`).

## Roadmap

Commands that are out of MVP scope (upload, XML export/import, `user`, command packages/extensibility, phar build) are tracked in `ROADMAP.md`. Don't build them unless asked.

## Workflow

- Commit messages follow Conventional Commits (`feat(page): add move subcommand`). Keep the body empty or one or two lines. The full explanation goes in the PR description.
- Before opening a PR, run `/ponytail:ponytail-review`, `/code-review` and `/security-review` on the diff. Run prose (README, `--help` text, PR descriptions) through `/humanizer:humanizer`.
