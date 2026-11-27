# Roadmap

These features are left out of the MVP on purpose.

- `mw upload <file> [--title=]`: upload a file.
- `mw export` / `mw import`: XML dumps, same as Special:Export and `importDump.php`. Until then, `mw maint dumpBackup` and `mw maint importDump` work.
- Command packages: let extensions and third-party packages register commands.
- Phar build and `mw cli update` for installs without Composer.
- `mw shell` / `mw eval`: MediaWiki already has `maintenance/run.php shell` and `eval`, so `mw maint` covers both for now.
