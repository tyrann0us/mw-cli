<?php

declare(strict_types=1);

namespace MwCli;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Settings\SettingsBuilder;

/**
 * The maintenance "script" MediaWiki runs after setup. It hands control back to the runner,
 * so commands execute with services, extensions and a database connection in place.
 */
class MwMaintenance extends Maintenance
{
    private static Runner $cli;
    private static int $code = 0;

    /**
     * Sets the runner that execute() hands control to. bootstrap.php calls it before setup.
     */
    public static function useRunner(Runner $cli): void
    {
        self::$cli = $cli;
    }

    /**
     * The command's exit code, once execute() ran.
     */
    public static function exitCode(): int
    {
        return self::$code;
    }

    /**
     * MW_FINAL_SETUP_CALLBACK: lets MediaWiki's MaintenanceRunner finish the settings, as
     * maintenance/run.php does. The runner is the global $runner, same name as in run.php.
     */
    public static function setupCallback(SettingsBuilder $settingsBuilder): void
    {
        $GLOBALS['runner']->setup($settingsBuilder);
    }

    public function execute(): bool
    {
        // Returning false (instead of exiting) lets MediaWiki commit and run deferred updates.
        self::$code = self::$cli->run();
        return self::$code === 0;
    }
}
