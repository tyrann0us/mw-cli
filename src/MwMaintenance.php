<?php

namespace MwCli;

use MediaWiki\Maintenance\Maintenance;

/**
 * The maintenance "script" MediaWiki runs after setup. It hands control back to the runner,
 * so commands execute with services, extensions and a database connection in place.
 */
class MwMaintenance extends Maintenance {
	public static Runner $cli;
	public static int $code = 0;

	public function execute() {
		// Returning false (instead of exiting) lets MediaWiki commit and run deferred updates.
		self::$code = self::$cli->run();
		return self::$code === 0;
	}
}
