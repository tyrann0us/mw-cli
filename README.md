# MW-CLI

[![PHP QA](https://github.com/tyrann0us/mw-cli/actions/workflows/quality-assurance-php.yml/badge.svg)](https://github.com/tyrann0us/mw-cli/actions/workflows/quality-assurance-php.yml)
[![Functional Tests](https://github.com/tyrann0us/mw-cli/actions/workflows/functional-tests.yml/badge.svg)](https://github.com/tyrann0us/mw-cli/actions/workflows/functional-tests.yml)
[![Lint](https://github.com/tyrann0us/mw-cli/actions/workflows/lint.yml/badge.svg)](https://github.com/tyrann0us/mw-cli/actions/workflows/lint.yml)
[![codecov](https://codecov.io/gh/tyrann0us/mw-cli/graph/badge.svg)](https://codecov.io/gh/tyrann0us/mw-cli)

MW-CLI is a command-line tool for [MediaWiki](https://www.mediawiki.org/). Its command, `mw`, reads, edits, moves and deletes pages, lists categories and recent changes, replaces text across many pages and runs maintenance scripts.

MW-CLI loads MediaWiki in the same process, so commands use MediaWiki's own PHP services, not the web API. It can also run a command on another host over SSH.

The [command reference](https://tyrann0us.github.io/mw-cli/) lists every command with its options and examples.

## Requirements

* PHP 8.1 or later on the host that runs MediaWiki.
* MediaWiki 1.43 or later.
* Semantic MediaWiki 7 or later, only for `mw ask`.

## Installation

MW-CLI has no runtime dependencies. Clone the repository on the host that runs MediaWiki and put `bin/mw` on your `PATH`:

```sh
git clone https://github.com/tyrann0us/mw-cli.git ~/mw-cli
ln -s ~/mw-cli/bin/mw ~/bin/mw
mw cli version
```

To update, run `git pull` in the clone.

## Usage

Run `mw` inside the MediaWiki directory, or pass the path with `--path`:

```console
$ cd /var/www/w
$ mw site info
$ mw --path=/var/www/w page get Main_Page
```

Some examples:

```console
$ mw page list --namespace=Help --format=json
$ echo "Hello [[world]]" | mw page put Sandbox --summary="Test edit"
$ mw page move Old_Title New_Title --reason="Rename"
$ mw category list --prefix=Fruit
$ mw search-replace '/colou?r/i' 'color' --namespace=0 --dry-run
$ mw recent-changes --limit=10
$ mw maint runJobs --maxjobs=100
```

`mw help` lists all commands, and `mw help <command>` shows the options and examples of one. Lists take `--format=table|json|csv|yaml|ids|count`, `--fields=` and `--field=`, and most take `--limit=`.

Edits are saved as the user given with `--user`, or as MediaWiki's "Maintenance script" user, so they show up in page history and recent changes like any other edit. `--quiet` hides success messages and warnings.

## Aliases and remote wikis

MW-CLI reads `~/.mw/config.ini` and the nearest `mw.ini` in the current directory or above. A section whose name starts with `@` is an alias:

```ini
[@local]
path = /var/www/w

[@prod]
ssh = deploy@wiki.example.org:2222
path = /var/www/w
user = Admin
```

```console
$ mw @local page list --limit=5
$ mw @prod site info
```

With `ssh`, MW-CLI runs the command on that host through the `mw` installed there. `--ssh=[<user>@]<host>[:<port>][<path>]` does the same without an alias.

## Contributing

Bug reports and pull requests are welcome. The tests run against a MediaWiki in Docker:

```sh
composer install
docker compose up -d --build --wait
composer test   # PHPUnit and Behat
composer lint   # php -l and phpcs
composer phpstan
```

Commands are PHP classes in `src/Command/`. Their docblocks are both the help text and the input validation.

## Copyright and license

Copyright (c) 2026 Philipp Bammes. Released under the [MIT License](LICENSE).
