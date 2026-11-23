<?php

namespace MwCli\Command;

use MediaWiki\Parser\ParserOptions;
use MwCli\Command;
use MwCli\Runner;

/**
 * Renders wikitext to HTML.
 */
class Parse extends Command {
	/**
	 * Renders wikitext from a file or stdin to HTML.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : File with the wikitext. Reads stdin if omitted.
	 *
	 * [--title=<title>]
	 * : Page the wikitext is rendered as, for {{PAGENAME}} and relative links. Default: API.
	 *
	 * ## EXAMPLES
	 *
	 *     mw parse page.wiki
	 *     echo "''Hello''" | mw parse --title=Sandbox
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$title = $this->services()->getTitleFactory()->newFromText( (string)( $assoc_args['title'] ?? 'API' ) )
			?? Runner::error( 'Invalid title.' );
		$file = $args[0] ?? null;
		if ( $file !== null && ( is_dir( $file ) || !is_readable( $file ) ) ) {
			Runner::error( "Can't read '$file'." );
		}
		$text = (string)file_get_contents( $file ?? 'php://stdin' );

		$options = ParserOptions::newFromAnon();
		$output = $this->services()->getParserFactory()->create()->parse( $text, $title, $options );
		$html = $output->runOutputPipeline( $options, [ 'enableSectionEditLinks' => false ] )->getContentHolderText();
		Runner::line( $html );
	}
}
