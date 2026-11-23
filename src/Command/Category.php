<?php

namespace MwCli\Command;

use MediaWiki\Category\Category as MwCategory;
use MediaWiki\Title\Title;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;

/**
 * Lists category members and the categories of pages.
 */
class Category extends Command {
	private const TYPES = [ NS_CATEGORY => 'subcat', NS_FILE => 'file' ];

	/**
	 * Lists the pages, subcategories and files in a category.
	 *
	 * The namespace prefix is optional and can be in the wiki's language. Members are
	 * sorted by their sort key, like on the category page.
	 *
	 * ## OPTIONS
	 *
	 * <category>
	 * : Category name, e.g. Fruit or Category:Fruit.
	 *
	 * [--namespace=<namespace>]
	 * : Only members in this namespace, by number or name.
	 *
	 * [--type=<type>]
	 * : Only members of this type: page, subcat or file.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show: title, namespace, type, id. Default: title, namespace, type.
	 *
	 * [--field=<field>]
	 * : Prints only the value of this field.
	 *
	 * ## EXAMPLES
	 *
	 *     mw category members Fruit
	 *     mw category members Category:Fruit --type=subcat --field=title
	 *     mw category members Fruit --namespace=File --format=count
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function members( array $args, array $assoc_args ): void {
		$type = $assoc_args['type'] ?? null;
		if ( $type !== null && !in_array( $type, [ 'page', 'subcat', 'file' ], true ) ) {
			Runner::error( 'Invalid type. Use one of: page, subcat, file.' );
		}
		$ns = null;
		if ( isset( $assoc_args['namespace'] ) ) {
			$ns = (string)$assoc_args['namespace'];
			$ns = ctype_digit( $ns ) ? (int)$ns : $this->services()->getContentLanguage()->getNsIndex( $ns );
			if ( $ns === false ) {
				Runner::error( "Unknown namespace: {$assoc_args['namespace']}." );
			}
		}

		$rows = [];
		foreach ( MwCategory::newFromTitle( $this->categoryTitle( $args[0] ) )->getMembers() as $title ) {
			$row = [
				'title' => $title->getPrefixedText(),
				'namespace' => $title->getNamespace(),
				'type' => self::TYPES[$title->getNamespace()] ?? 'page',
				'id' => $title->getArticleID(),
			];
			if ( ( $ns === null || $ns === $row['namespace'] ) && ( $type === null || $type === $row['type'] ) ) {
				$rows[] = $row;
			}
		}
		Formatter::items( $rows, $assoc_args, [ 'title', 'namespace', 'type' ] );
	}

	/**
	 * Lists the categories that pages are in, one row per page and category.
	 *
	 * ## OPTIONS
	 *
	 * <title>...
	 * : Page titles.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show: title, category. Default: both.
	 *
	 * [--field=<field>]
	 * : Prints only the value of this field.
	 *
	 * ## EXAMPLES
	 *
	 *     mw category of Main_Page
	 *     mw category of Apple Banana --field=category
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function of( array $args, array $assoc_args ): void {
		$pages = [];
		foreach ( $args as $name ) {
			$title = $this->services()->getTitleFactory()->newFromText( $name );
			if ( !$title?->exists() ) {
				Runner::error( "Page not found: $name." );
			}
			$pages[] = $title;
		}

		$rows = [];
		$factory = $this->services()->getWikiPageFactory();
		foreach ( $pages as $page ) {
			$categories = [];
			foreach ( $factory->newFromTitle( $page )->getCategories() as $category ) {
				$categories[] = $category->getPrefixedText();
			}
			sort( $categories );
			foreach ( $categories as $category ) {
				$rows[] = [ 'title' => $page->getPrefixedText(), 'category' => $category ];
			}
		}
		Formatter::items( $rows, $assoc_args, [ 'title', 'category' ] );
	}

	/**
	 * The category title for a name with or without the (localized) namespace prefix.
	 */
	private function categoryTitle( string $name ): Title {
		$factory = $this->services()->getTitleFactory();
		$title = $factory->newFromText( $name, NS_CATEGORY );
		if ( $title?->getNamespace() !== NS_CATEGORY ) {
			// "Help:Foo" names the category Category:Help:Foo, not the help page.
			$title = $factory->makeTitleSafe( NS_CATEGORY, $name );
		}
		return $title ?? Runner::error( "Invalid category name: $name." );
	}
}
