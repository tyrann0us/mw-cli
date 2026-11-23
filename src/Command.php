<?php

namespace MwCli;

use MediaWiki\MediaWikiServices;

/**
 * Base for classes in src/Command/. The file name is the command name (SearchReplace.php is
 * `mw search-replace`). Public methods are subcommands, or the class has __invoke() and none.
 * Each takes (array $args, array $assoc_args) and documents its options in its docblock, see Synopsis.
 */
abstract class Command {
	/** False for commands that run without loading MediaWiki. */
	public const NEEDS_WIKI = true;

	public function __construct( protected Runner $cli ) {
	}

	protected function services(): MediaWikiServices {
		return MediaWikiServices::getInstance();
	}
}
