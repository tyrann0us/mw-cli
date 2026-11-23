<?php

namespace MwCli;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

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

	/**
	 * The user from --user (name or ID), or MediaWiki's "Maintenance script" system user.
	 */
	protected function performer(): User {
		$name = $this->cli->global['user'] ?? null;
		if ( $name === null || $name === false ) {
			return User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
		}
		$factory = $this->services()->getUserFactory();
		$user = ctype_digit( (string)$name )
			? $factory->newFromId( (int)$name )
			: $factory->newFromName( (string)$name );
		if ( !$user || !$user->isRegistered() ) {
			Runner::error( "Invalid user '$name'." );
		}
		return $user;
	}
}
