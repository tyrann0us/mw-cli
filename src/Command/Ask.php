<?php

namespace MwCli\Command;

use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use SMW\ProcessingErrorMsgHandler;
use SMW\Query\PrintRequest;
use SMW\Query\Query;
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
		if ( !$this->services()->getExtensionRegistry()->isLoaded( 'SemanticMediaWiki' ) ) {
			Runner::error( 'Semantic MediaWiki is not installed on this wiki.' );
		}
		// Split on | outside [[ ]], so [[A::x||y]] stays one condition.
		$params = preg_split( '/\|(?![^\[\]]*\]\])/', $args[0] ) ?: [];
		if ( isset( $assoc_args['limit'] ) ) {
			if ( !is_string( $assoc_args['limit'] ) || !ctype_digit( $assoc_args['limit'] ) ) {
				Runner::error( '--limit must be a number.' );
			}
			$params[] = 'limit=' . $assoc_args['limit'];
		}
		[ $query ] = QueryProcessor::getQueryAndParamsFromFunctionParams(
			$params, SMW_OUTPUT_WIKI, QueryProcessor::SPECIAL_PAGE, false
		);
		// Rows, even if the query asks for format=count or format=debug.
		$query->setQueryMode( Query::MODE_INSTANCES );
		// The store adds errors too, e.g. for unknown properties, and then returns no rows.
		$result = ServicesFactory::getInstance()->getStore()->getQueryResult( $query );
		$errors = ProcessingErrorMsgHandler::normalizeAndDecodeMessages( $result->getErrors() );
		if ( $errors ) {
			Runner::error( implode( ' ', $errors ) );
		}
		// One field per printout, so the columns are the same with no results.
		$fields = array_map(
			static fn ( $pr ) => $pr->isMode( PrintRequest::PRINT_THIS ) ? 'title' : $pr->getLabel(),
			$result->getPrintRequests()
		);
		$rows = [];
		// A row is [] with mainlabel=- and no printouts, so compare with false.
		for ( $columns = $result->getNext(); $columns !== false; $columns = $result->getNext() ) {
			$row = [];
			foreach ( $columns as $i => $column ) {
				$values = [];
				for ( $value = $column->getNextDataValue(); $value; $value = $column->getNextDataValue() ) {
					$values[] = $value->getWikiValue();
				}
				$row[$fields[$i]] = implode( ', ', $values );
			}
			$rows[] = $row;
		}
		Formatter::items( $rows, $assoc_args, $fields );
	}
}
