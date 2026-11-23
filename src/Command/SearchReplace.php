<?php

namespace MwCli\Command;

use MediaWiki\Category\Category;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MwCli\Command;
use MwCli\Runner;
use StatusValue;
use Wikimedia\Diff\Diff;
use Wikimedia\Diff\UnifiedDiffFormatter;

/**
 * Replaces text in many pages with a regular expression.
 */
class SearchReplace extends Command {
	/**
	 * Replaces text in many pages with a regular expression.
	 *
	 * Without --category or --namespace, all pages in the wiki's content namespaces
	 * ($wgContentNamespaces, usually just the main namespace) are searched. With both,
	 * only category members in that namespace. Pages are read and checked before anything
	 * is saved. Pages that were edited after
	 * they were read, or fail to save, are skipped with a warning.
	 *
	 * ## OPTIONS
	 *
	 * <regex>
	 * : PCRE pattern with delimiters, e.g. '/colou?r/i'.
	 *
	 * <replacement>
	 * : Replacement text. $1, $2 … insert the groups the pattern captured.
	 *
	 * [--category=<category>]
	 * : Only pages in this category, with or without the namespace prefix.
	 *
	 * [--namespace=<namespace>]
	 * : Only pages in this namespace, by number or name.
	 *
	 * [--dry-run]
	 * : Print a unified diff for each page that would change, but save nothing.
	 *
	 * [--summary=<summary>]
	 * : Edit summary. Default: "Replace <regex> with "<replacement>" (mw search-replace)".
	 *
	 * [--minor]
	 * : Mark the edits as minor.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     mw search-replace '/\bteh\b/' 'the' --dry-run
	 *     mw search-replace '/\[\[Category:Old\]\]/' '[[Category:New]]' --category=Old --yes
	 *     mw --user=Admin search-replace '/(\d+) EUR/' '€$1' --namespace=Help --minor
	 *
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		[ $regex, $replacement ] = $args;
		set_error_handler( static function ( int $errno, string $message ) use ( $regex ): never {
			Runner::error( "Invalid regex '$regex': " . str_replace( 'preg_match(): ', '', $message ) . '.' );
		} );
		try {
			preg_match( $regex, '' );
		} finally {
			restore_error_handler();
		}
		$pages = $this->pages( $assoc_args );
		$performer = $this->performer();

		$factory = $this->services()->getWikiPageFactory();
		$dryRun = !empty( $assoc_args['dry-run'] );
		$changes = [];
		$total = 0;
		foreach ( $pages as $page ) {
			$wikiPage = $factory->newFromTitle( $page );
			$name = $wikiPage->getTitle()->getPrefixedText();
			$content = $wikiPage->getContent( RevisionRecord::RAW );
			$old = $content?->serialize() ?? '';
			$new = preg_replace( $regex, $replacement, $old, -1, $count )
				?? Runner::error( "Regex failed on '$name': " . preg_last_error_msg() . '.' );
			if ( $content === null || $new === $old ) {
				continue;
			}
			$newContent = $content->getContentHandler()->unserializeContent( $new );
			if ( !$newContent->isValid() ) {
				Runner::error( "The replacement would make '$name' invalid {$content->getModel()} content." );
			}
			$changes[] = [ $page, $name, $wikiPage->getLatest(), $newContent, $count ];
			$total += $count;
			if ( $dryRun ) {
				$diff = new Diff( explode( "\n", $old ), explode( "\n", $new ) );
				Runner::line( "--- $name\n+++ $name\n" . rtrim( ( new UnifiedDiffFormatter() )->format( $diff ), "\n" ) );
			}
		}

		if ( !$changes ) {
			Runner::success( 'Nothing to replace.' );
			return;
		}
		$summary = self::plural( count( $changes ), 'page' ) . ', ' . self::plural( $total, 'replacement' );
		if ( $dryRun ) {
			Runner::success( "Would change $summary." );
			return;
		}
		Runner::confirm( "Change $summary?", $assoc_args );

		$comment = CommentStoreComment::newUnsavedComment( (string)(
			$assoc_args['summary'] ?? "Replace $regex with \"$replacement\" (mw search-replace)"
		) );
		$flags = EDIT_UPDATE | ( !empty( $assoc_args['minor'] ) ? EDIT_MINOR : 0 );
		$statusFormatter = $this->services()->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );
		$saved = 0;
		foreach ( $changes as [ $page, $name, $revisionId, $content, $count ] ) {
			// A new WikiPage, so the conflict check reads the current revision, not the one cached above.
			$updater = $this->services()->getPageUpdaterFactory()
				->newPageUpdater( $factory->newFromTitle( $page ), $performer );
			$revision = null;
			if ( $updater->hasEditConflict( $revisionId ) ) {
				$status = StatusValue::newFatal( 'edit-conflict' );
			} else {
				$updater->setContent( SlotRecord::MAIN, $content );
				$revision = $updater->saveRevision( $comment, $flags );
				$status = $updater->getStatus();
			}
			// Earlier pages are saved already, so a failed page doesn't stop the others.
			if ( !$status->isOK() ) {
				Runner::warning( "Skipped '$name': " . $statusFormatter->getMessage( $status )->text() );
				continue;
			}
			if ( $revision === null ) {
				// Pre-save transform, e.g. trimming trailing spaces, can undo the change.
				Runner::warning( "No change: '$name' would be saved with the same text." );
				continue;
			}
			$replaced = self::plural( $count, 'replacement' );
			Runner::success( "Saved '$name' as revision {$revision->getId()} ($replaced)." );
			$saved++;
		}
		Runner::success( 'Changed ' . self::plural( $saved, 'page' ) . '.' );
	}

	/**
	 * The pages in scope: category members, optionally in one namespace, or all pages in a
	 * namespace or the content namespaces.
	 *
	 * @param array<string,string|bool> $assoc_args
	 * @return iterable<PageIdentity>
	 */
	private function pages( array $assoc_args ): iterable {
		$namespace = isset( $assoc_args['namespace'] ) ? $this->namespace( (string)$assoc_args['namespace'] ) : null;
		if ( isset( $assoc_args['category'] ) ) {
			$name = (string)$assoc_args['category'];
			$title = $this->services()->getTitleFactory()->newFromText( $name, NS_CATEGORY );
			if ( !$title?->inNamespace( NS_CATEGORY ) ) {
				Runner::error( "Invalid category '$name'." );
			}
			$members = iterator_to_array( Category::newFromTitle( $title )->getMembers(), false );
			return array_filter( $members, static fn ( $title ) => $namespace === null || $title->inNamespace( $namespace ) );
		}
		$namespaces = $namespace ?? $this->services()->getMainConfig()->get( MainConfigNames::ContentNamespaces );
		$db = $this->services()->getConnectionProvider()->getReplicaDatabase();
		return $this->services()->getPageStore()->newSelectQueryBuilder( $db )
			->where( [ 'page_namespace' => $namespaces ] )
			->orderBy( [ 'page_namespace', 'page_title' ] )
			->caller( __METHOD__ )
			->fetchPageRecords();
	}

	private static function plural( int $count, string $noun ): string {
		return "$count $noun" . ( $count === 1 ? '' : 's' );
	}
}
