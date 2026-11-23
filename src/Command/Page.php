<?php

namespace MwCli\Command;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use StatusValue;
use Wikimedia\ParamValidator\TypeDef\ExpiryDef;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * Reads, edits, lists, deletes, moves, protects and purges pages.
 */
class Page extends Command {
	/**
	 * Prints the wikitext of a page.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : Page title.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page get Main_Page
	 *     mw page get 'Help:Contents' > contents.wiki
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function get( array $args, array $assoc_args ): void {
		$title = $this->title( $args[0] );
		$revision = $this->services()->getRevisionLookup()->getRevisionByTitle( $title );
		$content = $revision?->getContent( SlotRecord::MAIN ) ?? $this->missing( $title );
		Runner::line( $content->serialize() );
	}

	/**
	 * Saves wikitext to a page, read from a file or stdin.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : Page title.
	 *
	 * [<file>]
	 * : File with the new text. Reads stdin if left out.
	 *
	 * [--summary=<summary>]
	 * : Edit summary. If left out, MediaWiki fills one in for new, blanked or replaced pages.
	 *
	 * [--minor]
	 * : Mark the edit as minor.
	 *
	 * [--create-only]
	 * : Fail if the page exists.
	 *
	 * [--no-create]
	 * : Fail if the page doesn't exist.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page put Sandbox sandbox.wiki --summary='Reset sandbox'
	 *     echo 'Hello' | mw --user=Admin page put Sandbox --minor
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function put( array $args, array $assoc_args ): void {
		$title = $this->title( $args[0] );
		$file = $args[1] ?? null;
		$text = $file === null ? stream_get_contents( STDIN ) : @file_get_contents( $file );
		if ( $text === false ) {
			Runner::error( "Can't read '$file'." );
		}
		$page = $this->services()->getWikiPageFactory()->newFromTitle( $title );
		if ( !empty( $assoc_args['create-only'] ) && $page->exists() ) {
			Runner::error( "Page '{$title->getPrefixedText()}' already exists." );
		}
		if ( ( $assoc_args['create'] ?? true ) === false && !$page->exists() ) {
			$this->missing( $title );
		}
		$content = $this->services()->getContentHandlerFactory()
			->getContentHandler( $page->getContentModel() )
			->unserializeContent( $text );

		$updater = $this->services()->getPageUpdaterFactory()->newPageUpdater( $page, $this->performer() );
		$updater->setContent( SlotRecord::MAIN, $content );
		$revision = $updater->saveRevision(
			CommentStoreComment::newUnsavedComment( (string)( $assoc_args['summary'] ?? '' ) ),
			EDIT_AUTOSUMMARY
				| ( !empty( $assoc_args['minor'] ) ? EDIT_MINOR : 0 )
				| ( !empty( $assoc_args['create-only'] ) ? EDIT_NEW : 0 )
				| ( ( $assoc_args['create'] ?? true ) === false ? EDIT_UPDATE : 0 )
		);
		$this->check( $updater->getStatus() );
		if ( $revision === null ) {
			Runner::warning( "No change: '{$title->getPrefixedText()}' already has this text." );
			return;
		}
		Runner::success( "Saved '{$title->getPrefixedText()}' as revision {$revision->getId()}." );
	}

	/**
	 * Lists pages.
	 *
	 * ## OPTIONS
	 *
	 * [--namespace=<namespace>]
	 * : Namespace number or name. All namespaces if left out.
	 *
	 * [--prefix=<prefix>]
	 * : Only titles that start with this, without the namespace.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show: id, title, namespace, redirect. Default: id, title.
	 *
	 * [--field=<field>]
	 * : Prints only the value of this field.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page list --namespace=Help
	 *     mw page list --prefix=Sandbox/ --format=ids
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function list( array $args, array $assoc_args ): void {
		$db = $this->services()->getConnectionProvider()->getReplicaDatabase();
		$query = $this->services()->getPageStore()->newSelectQueryBuilder( $db )
			->orderBy( [ 'page_namespace', 'page_title' ] )
			->caller( __METHOD__ );
		$namespace = isset( $assoc_args['namespace'] ) ? $this->namespace( (string)$assoc_args['namespace'] ) : null;
		if ( $namespace !== null ) {
			$query->whereNamespace( $namespace );
		}
		if ( isset( $assoc_args['prefix'] ) ) {
			$prefix = strtr( (string)$assoc_args['prefix'], ' ', '_' );
			if ( $this->services()->getNamespaceInfo()->isCapitalized( $namespace ?? NS_MAIN ) ) {
				$prefix = $this->services()->getContentLanguage()->ucfirst( $prefix );
			}
			$query->where( $db->expr( 'page_title', IExpression::LIKE, new LikeValue( $prefix, $db->anyString() ) ) );
		}

		$formatter = $this->services()->getTitleFormatter();
		$rows = [];
		foreach ( $query->fetchPageRecords() as $page ) {
			$rows[] = [
				'id' => $page->getId(),
				'title' => $formatter->getPrefixedText( $page ),
				'namespace' => $page->getNamespace(),
				'redirect' => $page->isRedirect(),
			];
		}
		Formatter::items( $rows, $assoc_args, [ 'id', 'title' ] );
	}

	/**
	 * Deletes pages.
	 *
	 * ## OPTIONS
	 *
	 * <title>...
	 * : Page titles.
	 *
	 * [--reason=<reason>]
	 * : Reason for the deletion log.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page delete Sandbox 'Old page' --reason=Cleanup --yes
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		$titles = $this->existing( $args );
		$question = count( $titles ) === 1 ? "Delete '{$titles[0]->getPrefixedText()}'?" : 'Delete ' . count( $titles ) . ' pages?';
		Runner::confirm( $question, $assoc_args );
		$performer = $this->performer();
		$factory = $this->services()->getDeletePageFactory();
		foreach ( $titles as $title ) {
			$this->check( $factory->newDeletePage( $title->toPageIdentity(), $performer )->deleteUnsafe( (string)( $assoc_args['reason'] ?? '' ) ) );
			Runner::success( "Deleted '{$title->getPrefixedText()}'." );
		}
	}

	/**
	 * Moves (renames) a page.
	 *
	 * Only this page moves. Its talk page and subpages stay where they are.
	 *
	 * ## OPTIONS
	 *
	 * <from>
	 * : Current title.
	 *
	 * <to>
	 * : New title.
	 *
	 * [--reason=<reason>]
	 * : Reason for the move log.
	 *
	 * [--no-redirect]
	 * : Don't leave a redirect at the old title.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page move Sandbox 'Help:Sandbox' --reason='Belongs in Help' --no-redirect
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function move( array $args, array $assoc_args ): void {
		$from = $this->title( $args[0] );
		$to = $this->title( $args[1] );
		$move = $this->services()->getMovePageFactory()->newMovePage( $from, $to );
		$this->check( $move->isValidMove() );
		$this->check( $move->move(
			$this->performer(),
			(string)( $assoc_args['reason'] ?? '' ),
			( $assoc_args['redirect'] ?? true ) !== false
		) );
		Runner::success( "Moved '{$from->getPrefixedText()}' to '{$to->getPrefixedText()}'." );
	}

	/**
	 * Changes who may edit or move a page.
	 *
	 * Levels come from $wgRestrictionLevels, e.g. autoconfirmed or sysop. Use `all` to lift a
	 * restriction. Restrictions you don't pass stay as they are.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : Page title.
	 *
	 * [--edit=<level>]
	 * : Who may edit.
	 *
	 * [--move=<level>]
	 * : Who may move.
	 *
	 * [--expiry=<expiry>]
	 * : When the new restrictions end, e.g. "1 week" or a date. Default: infinite.
	 *
	 * [--reason=<reason>]
	 * : Reason for the protection log.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page protect Main_Page --edit=sysop --move=sysop --reason='High traffic'
	 *     mw page protect Sandbox --edit=autoconfirmed --expiry='2 weeks'
	 *     mw page protect Sandbox --edit=all
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function protect( array $args, array $assoc_args ): void {
		$title = $this->existing( [ $args[0] ] )[0];
		if ( !isset( $assoc_args['edit'] ) && !isset( $assoc_args['move'] ) ) {
			Runner::error( 'Pass --edit or --move.' );
		}
		$levels = array_diff( $this->services()->getMainConfig()->get( MainConfigNames::RestrictionLevels ), [ '' ] );
		$input = (string)( $assoc_args['expiry'] ?? 'infinity' );
		$expiry = in_array( $input, ExpiryDef::INFINITY_VALS, true ) ? 'infinity' : strtotime( $input );
		if ( $expiry === false || ( $expiry !== 'infinity' && $expiry <= time() ) ) {
			Runner::error( "Invalid expiry '$input'. Use a future time like '1 week' or 'infinite'." );
		}

		$store = $this->services()->getRestrictionStore();
		$limit = [];
		$expiries = [];
		// doUpdateRestrictions() replaces all of them, so pass on the ones we don't change.
		foreach ( $store->listApplicableRestrictionTypes( $title ) as $action ) {
			$level = $assoc_args[$action] ?? null;
			if ( $level === null ) {
				$limit[$action] = implode( ',', $store->getRestrictions( $title, $action ) );
				$expiries[$action] = $store->getRestrictionExpiry( $title, $action );
			} elseif ( $level === 'all' || in_array( $level, $levels, true ) ) {
				$limit[$action] = $level === 'all' ? '' : $level;
				$expiries[$action] = $expiry;
			} else {
				Runner::error( "Invalid --$action level '$level'. Use all, " . implode( ', ', $levels ) . '.' );
			}
		}

		$cascade = $store->areRestrictionsCascading( $title );
		$page = $this->services()->getWikiPageFactory()->newFromTitle( $title );
		$this->check( $page->doUpdateRestrictions(
			$limit, $expiries, $cascade, (string)( $assoc_args['reason'] ?? '' ), $this->performer()
		) );
		$now = array_map( static fn ( $action, $level ) => "$action=" . ( $level ?: 'all' ), array_keys( $limit ), $limit );
		Runner::success( "Protected '{$title->getPrefixedText()}': " . implode( ', ', $now ) . '.' );
	}

	/**
	 * Clears the parser and page caches of pages.
	 *
	 * ## OPTIONS
	 *
	 * <title>...
	 * : Page titles.
	 *
	 * ## EXAMPLES
	 *
	 *     mw page purge Main_Page 'Help:Contents'
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function purge( array $args, array $assoc_args ): void {
		foreach ( $this->existing( $args ) as $title ) {
			$name = $title->getPrefixedText();
			$purged = $this->services()->getWikiPageFactory()->newFromTitle( $title )->doPurge();
			$purged ? Runner::success( "Purged '$name'." ) : Runner::warning( "A hook stopped purging '$name'." );
		}
	}

	private function title( string $name ): Title {
		$title = $this->services()->getTitleFactory()->newFromText( $name );
		if ( $title === null || !$title->canExist() ) {
			Runner::error( "Invalid page title '$name'." );
		}
		return $title;
	}

	/**
	 * Titles for these names, duplicates removed. Fails before anything changes if a page doesn't exist.
	 *
	 * @param string[] $names
	 * @return list<Title>
	 */
	private function existing( array $names ): array {
		$titles = [];
		foreach ( $names as $name ) {
			$title = $this->title( $name );
			$titles[$title->getPrefixedText()] = $title;
		}
		$missing = array_keys( array_filter( $titles, static fn ( $title ) => !$title->exists() ) );
		if ( $missing ) {
			Runner::error( "Page not found: '" . implode( "', '", $missing ) . "'." );
		}
		return array_values( $titles );
	}

	private function namespace( string $name ): int {
		$index = ctype_digit( $name )
			? (int)$name
			: $this->services()->getContentLanguage()->getNsIndex( strtr( $name, ' ', '_' ) );
		if ( $index === false || !$this->services()->getNamespaceInfo()->exists( $index ) ) {
			Runner::error( "Unknown namespace '$name'." );
		}
		return $index;
	}

	private function missing( Title $title ): never {
		Runner::error( "Page '{$title->getPrefixedText()}' doesn't exist." );
	}

	/**
	 * Fails with MediaWiki's message if the status isn't OK.
	 *
	 * A template, not StatusValue<mixed>: StatusValue only became generic after 1.43.
	 *
	 * @template T of StatusValue
	 * @param T $status
	 */
	private function check( StatusValue $status ): void {
		if ( !$status->isOK() ) {
			$formatter = $this->services()->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );
			Runner::error( $formatter->getMessage( $status )->text() );
		}
	}
}
