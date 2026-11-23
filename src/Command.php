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
		$name = $this->cli->flag( 'user' );
		if ( $name === null ) {
			// No service replaces this in 1.43, and it isn't deprecated.
			return User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] )
				?? Runner::error( "Can't create the '" . User::MAINTENANCE_SCRIPT_USER . "' user." );
		}
		$factory = $this->services()->getUserFactory();
		$user = ctype_digit( $name ) ? $factory->newFromId( (int)$name ) : $factory->newFromName( $name );
		if ( $user === null || !$user->isRegistered() ) {
			Runner::error( "Invalid user '$name'." );
		}
		return $user;
	}
}
