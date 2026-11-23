<?php

namespace MwCli;

use MediaWiki\Maintenance\Maintenance;

/**
 * The maintenance "script" MediaWiki runs after setup. It hands control back to the runner,
 * so commands execute with services, extensions and a database connection in place.
 */
class MwMaintenance extends Maintenance {
	public static Runner $cli;

	public function execute() {
		// Returning false (instead of exiting) lets MediaWiki commit and run deferred updates.
		return self::$cli->run() === 0;
	}
}
