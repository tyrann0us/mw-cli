<?php

namespace MwCli\Command;

/**
 * Lists pages that transclude a page.
 */
class Transclusions extends Backlinks {
	protected const TABLE = 'templatelinks';
	protected const FROM = 'tl_from';

	/**
	 * Lists pages that transclude a page, usually a template.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : The transcluded page, with its namespace (Template:Infobox).
	 *
	 * [--namespace=<ns>]
	 * : Only list pages in this namespace: a number (0 for main) or a name.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show: id, title, namespace. Default: id, title.
	 *
	 * [--field=<field>]
	 * : Prints only the value of this field.
	 *
	 * ## EXAMPLES
	 *
	 *     mw transclusions Template:Infobox
	 *     mw transclusions Template:Stub --format=count
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		parent::__invoke( $args, $assoc_args );
	}
}
