<?php

declare(strict_types=1);

namespace MwCli\Command;

use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;

/**
 * Shows information about mw itself.
 */
class Cli extends Command
{
    public const NEEDS_WIKI = false;

    /**
     * Prints the mw version.
     *
     * ## EXAMPLES
     *
     *     mw cli version
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function version(array $args, array $assocArgs): void
    {
        Runner::line('mw ' . Runner::VERSION);
    }

    /**
     * Shows the PHP binary, mw version and config files in use.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show.
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw cli info --format=json
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function info(array $args, array $assocArgs): void
    {
        Formatter::item([
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'php_binary' => PHP_BINARY,
            'php_version' => PHP_VERSION,
            'mw_cli_dir' => dirname(__DIR__, 2),
            'mw_cli_version' => Runner::VERSION,
            'config_files' => implode(', ', $this->cli->configFiles()),
        ], $assocArgs);
    }
}
