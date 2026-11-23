<?php

namespace MwCli\Command;

use MediaWiki\Registration\ExtensionRegistry;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use SMW\ProcessingErrorMsgHandler;
use SMW\Query\QueryProcessor;
use SMW\Services\ServicesFactory;

/**
 * Runs a Semantic MediaWiki query.
 */
class Ask extends Command {
	/**
	 * Runs a Semantic MediaWiki query and lists the pages it finds.
	 *
	 * The query uses #ask syntax: conditions, then printouts and parameters separated by `|`.
	 * Each page is a row with its title and one field per printout. Multiple values are
	 * joined with ", ".
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The query, e.g. '[[Category:Building]]|?Street'.
	 *
	 * [--limit=<n>]
	 * : Maximum number of results. Defaults to Semantic MediaWiki's limit.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show.
	 *
	 * [--field=<field>]
	 * : Prints only the value of this field.
	 *
	 * ## EXAMPLES
	 *
	 *     mw ask '[[Category:Building]]|?Street'
	 *     mw ask '[[Street::Main Street]]' --limit=10 --format=ids
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
			Runner::error( 'Semantic MediaWiki is not installed on this wiki.' );
		}
		$params = explode( '|', $args[0] );
		if ( isset( $assoc_args['limit'] ) ) {
			if ( !ctype_digit( (string)$assoc_args['limit'] ) ) {
				Runner::error( '--limit must be a number.' );
			}
			$params[] = 'limit=' . $assoc_args['limit'];
		}
		[ $query ] = QueryProcessor::getQueryAndParamsFromFunctionParams(
			$params, SMW_OUTPUT_WIKI, QueryProcessor::SPECIAL_PAGE, false
		);
		$errors = ProcessingErrorMsgHandler::normalizeAndDecodeMessages( $query->getErrors() );
		if ( $errors ) {
			Runner::error( implode( ' ', $errors ) );
		}
		$result = ServicesFactory::getInstance()->getStore()->getQueryResult( $query );
		$rows = [];
		for ( $columns = $result->getNext(); $columns; $columns = $result->getNext() ) {
			$row = [];
			foreach ( $columns as $column ) {
				$values = [];
				for ( $value = $column->getNextDataValue(); $value; $value = $column->getNextDataValue() ) {
					$values[] = $value->getWikiValue();
				}
				// The page itself is the printout without a label.
				$label = $column->getPrintRequest()->getLabel();
				$row[$label === '' ? 'title' : $label] = implode( ', ', $values );
			}
			$rows[] = $row;
		}
		Formatter::items( $rows, $assoc_args, array_keys( $rows[0] ?? [ 'title' => null ] ) );
	}
}
