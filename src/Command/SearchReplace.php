<?php

declare(strict_types=1);

namespace MwCli\Command;

use MediaWiki\Category\Category;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\Content;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MwCli\Command;
use MwCli\Runner;
use StatusValue;
use Wikimedia\Diff\Diff;
use Wikimedia\Diff\UnifiedDiffFormatter;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Replaces text in many pages with a regular expression.
 */
class SearchReplace extends Command
{
    /**
     * Replaces text in many pages with a regular expression.
     *
     * Without --category or --namespace, it searches the content namespaces
     * ($wgContentNamespaces, usually only the main namespace). With both, it searches the
     * category's members in that namespace. Every page is read and checked before the first
     * save. A page that was edited in the meantime, or that fails to save, is skipped with a
     * warning, and the command exits with 1 at the end.
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
     * : Only pages in this category, e.g. Fruit or Category:Fruit. The prefix can be in the
     * wiki's language.
     *
     * [--namespace=<namespace>]
     * : Only pages in this namespace, by number or name.
     *
     * [--dry-run]
     * : Print a unified diff for each page that would change, but save nothing.
     *
     * [--summary=<summary>]
     * : Edit summary. If left out or empty:
     * "Replace <regex> with "<replacement>" (mw search-replace)".
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
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        [$regex, $replacement] = $args;
        self::checkRegex($regex);
        $pages = $this->pages($assocArgs);
        $dryRun = !empty($assocArgs['dry-run']);
        [$changes, $total] = $this->changes($pages, $regex, $replacement, $dryRun);

        if (!$changes) {
            Runner::success($dryRun ? '0 replacements to be made.' : 'Made 0 replacements.');
            return;
        }
        $summary = self::plural($total, 'replacement') . ' in '
            . self::plural(count($changes), 'page');
        if ($dryRun) {
            Runner::success("$summary to be made.");
            return;
        }
        Runner::confirm("Make $summary?", $assocArgs);
        $performer = $this->performer();

        $comment = CommentStoreComment::newUnsavedComment((string) (
            ($assocArgs['summary'] ?? '')
                ?: "Replace $regex with \"$replacement\" (mw search-replace)"
        ));
        $flags = EDIT_UPDATE | (!empty($assocArgs['minor']) ? EDIT_MINOR : 0);
        $results = ['saved' => 0, 'skipped' => 0, 'unchanged' => 0];
        $made = 0;
        foreach ($changes as $change) {
            $result = $this->save($change, $performer, $comment, $flags);
            $results[$result]++;
            $made += $result === 'saved' ? $change[4] : 0;
        }
        $changed = 'Made ' . self::plural($made, 'replacement') . ' in '
            . self::plural($results['saved'], 'page');
        if ($results['skipped']) {
            Runner::error("$changed, skipped " . self::plural($results['skipped'], 'page') . '.');
        }
        Runner::success("$changed.");
    }

    /**
     * Fails with the compiler's message if the pattern doesn't compile.
     */
    private static function checkRegex(string $regex): void
    {
        set_error_handler(static function (int $errno, string $message) use ($regex): never {
            $message = str_replace('preg_match(): ', '', $message);
            Runner::error("Invalid regex '$regex': $message.");
        });
        try {
            preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Reads every page and replaces in memory. With $dryRun, prints a diff per changed page.
     *
     * @param iterable<PageIdentity> $pages
     * @return array{0: list<array{0: PageIdentity, 1: string, 2: int, 3: Content, 4: int}>, 1: int}
     *     Page, name, revision ID read, new content and replacement count per changed page, and
     *     the total number of replacements.
     */
    private function changes(iterable $pages, string $regex, string $replacement, bool $dryRun): array
    {
        $factory = $this->services()->getWikiPageFactory();
        $changes = [];
        $total = 0;
        foreach ($pages as $page) {
            $wikiPage = $factory->newFromTitle($page);
            // From the primary, like the edit conflict check in save().
            $wikiPage->loadPageData(IDBAccessObject::READ_LATEST);
            $name = $wikiPage->getTitle()->getPrefixedText();
            $content = $wikiPage->getContent(RevisionRecord::RAW);
            $old = $content?->serialize() ?? '';
            $new = preg_replace($regex, $replacement, $old, -1, $count)
                ?? Runner::error("Regex failed on '$name': " . preg_last_error_msg() . '.');
            if ($content === null || $new === $old) {
                continue;
            }
            $newContent = $content->getContentHandler()->unserializeContent($new);
            if (!$newContent->isValid()) {
                Runner::error(
                    "The replacement would make '$name' invalid {$content->getModel()} content."
                );
            }
            $changes[] = [$page, $name, $wikiPage->getLatest(), $newContent, $count];
            $total += $count;
            if ($dryRun) {
                $diff = new Diff(explode("\n", $old), explode("\n", $new));
                $lines = rtrim((new UnifiedDiffFormatter())->format($diff), "\n");
                Runner::line("--- $name\n+++ $name\n$lines");
            }
        }
        return [$changes, $total];
    }

    /**
     * Saves one change from changes().
     *
     * @param array{0: PageIdentity, 1: string, 2: int, 3: Content, 4: int} $change
     * @return 'saved'|'skipped'|'unchanged'
     */
    private function save(
        array $change,
        User $performer,
        CommentStoreComment $comment,
        int $flags
    ): string {

        [$page, $name, $revisionId, $content, $count] = $change;
        // A new WikiPage, so the conflict check reads the current revision, not the one cached
        // in changes().
        $updater = $this->services()->getPageUpdaterFactory()->newPageUpdater(
            $this->services()->getWikiPageFactory()->newFromTitle($page),
            $performer
        );
        $revision = null;
        $conflict = $updater->hasEditConflict($revisionId);
        if (!$conflict) {
            $updater->setContent(SlotRecord::MAIN, $content);
            $revision = $updater->saveRevision($comment, $flags);
        }
        $status = $conflict ? StatusValue::newFatal('edit-conflict') : $updater->getStatus();
        // Earlier pages are saved already, so a failed page doesn't stop the others.
        if (!$status->isOK()) {
            Runner::warning("Skipped '$name': " . $this->statusText($status));
            return 'skipped';
        }
        if ($revision === null) {
            // Pre-save transform, e.g. trimming trailing spaces, can undo the change.
            Runner::warning("No change: '$name' would be saved with the same text.");
            return 'unchanged';
        }
        // Like maintenance scripts, so a long run doesn't leave replicas behind.
        $this->services()->getDBLoadBalancerFactory()->waitForReplication();
        $replaced = self::plural($count, 'replacement');
        // Progress only: the one Success line comes at the end.
        Runner::progress("Updated page '$name' (revision {$revision->getId()}, $replaced).");
        return 'saved';
    }

    /**
     * The pages in scope: category members, optionally in one namespace, or all pages in a
     * namespace or the content namespaces.
     *
     * @param array<string,string|bool> $assocArgs
     * @return iterable<PageIdentity>
     */
    private function pages(array $assocArgs): iterable
    {
        $namespace = isset($assocArgs['namespace'])
            ? $this->namespace((string) $assocArgs['namespace'])
            : null;
        if (isset($assocArgs['category'])) {
            $category = Category::newFromTitle($this->category((string) $assocArgs['category']));
            $members = iterator_to_array($category->getMembers(), false);
            return array_filter(
                $members,
                static fn (Title $member): bool => $namespace === null
                    || $member->inNamespace($namespace)
            );
        }
        $namespaces = $namespace ?? $this->services()->getNamespaceInfo()->getContentNamespaces();
        $db = $this->services()->getConnectionProvider()->getReplicaDatabase();
        return $this->services()->getPageStore()->newSelectQueryBuilder($db)
            ->where(['page_namespace' => $namespaces])
            ->orderBy(['page_namespace', 'page_title'])
            ->caller(__METHOD__)
            ->fetchPageRecords();
    }

    private static function plural(int $count, string $noun): string
    {
        return "$count $noun" . ($count === 1 ? '' : 's');
    }
}
