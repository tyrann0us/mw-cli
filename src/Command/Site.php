<?php

namespace MwCli\Command;

use MediaWiki\MainConfigNames;
use MwCli\Command;
use MwCli\Formatter;

/**
 * Shows information about the wiki.
 */
class Site extends Command {
	/**
	 * Shows the wiki's name, MediaWiki version, server, install path, database type and language.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show.
	 *
	 * [--field=<field>]
	 * : Prints only the value of this field.
	 *
	 * ## EXAMPLES
	 *
	 *     mw site info
	 *     mw @prod site info --field=version
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function info( array $args, array $assoc_args ): void {
		$config = $this->services()->getMainConfig();
		Formatter::item( [
			'name' => $config->get( MainConfigNames::Sitename ),
			'version' => MW_VERSION,
			'server' => $config->get( MainConfigNames::Server ),
			'path' => $config->get( MainConfigNames::BaseDirectory ),
			'database' => $config->get( MainConfigNames::DBtype ),
			'language' => $config->get( MainConfigNames::LanguageCode ),
		], $assoc_args );
	}
}
