<?php

namespace MwCli\Command;

use MwCli\Command;
use MwCli\ExitException;

/**
 * Runs a MediaWiki maintenance script.
 */
class Maint extends Command {
	// The script loads MediaWiki itself through maintenance/run.php.
	public const NEEDS_WIKI = false;

	/**
	 * Runs a MediaWiki maintenance script through maintenance/run.php.
	 *
	 * Options after the script name go to the script. Put them after `--` if they clash
	 * with mw's own flags (--path, --ssh).
	 *
	 * ## OPTIONS
	 *
	 * <script>
	 * : Script name, e.g. runJobs or update.
	 *
	 * [<args>...]
	 * : Arguments for the script.
	 *
	 * [--<option>=<value>]
	 * : Options for the script.
	 *
	 * ## EXAMPLES
	 *
	 *     mw maint runJobs --maxjobs=100
	 *     mw maint edit --user=Admin Main_Page
	 *     mw maint importDump -- --path=dump.xml
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Raw tokens, so repeated options and their order reach the script unchanged.
		$cmd = array_map( 'escapeshellarg', [
			PHP_BINARY, $this->cli->wikiPath() . '/maintenance/run.php', ...$this->cli->rawArgs(),
		] );
		passthru( implode( ' ', $cmd ), $code );
		if ( $code !== 0 ) {
			throw new ExitException( '', $code );
		}
	}
}
