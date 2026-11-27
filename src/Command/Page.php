<?php

declare(strict_types=1);

namespace MwCli\Command;

use MediaWiki\Category\Category;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use StatusValue;
use Wikimedia\ParamValidator\TypeDef\ExpiryDef;

/**
 * Reads, edits, lists, deletes, moves, protects and purges pages, and lists their categories.
 */
class Page extends Command
{
    public const GROUPS = ['category' => 'Lists the categories of a page.'];

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
     * @param array<string,string|bool> $assocArgs
     */
    public function get(array $args, array $assocArgs): void
    {
        $title = $this->title($args[0]);
        $revision = $this->services()->getRevisionLookup()->getRevisionByTitle($title);
        $content = $revision?->getContent(SlotRecord::MAIN) ?? $this->missing($title);
        Runner::line($content->serialize());
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
     * [--porcelain]
     * : Print only the page ID, for scripts.
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
     * @param array<string,string|bool> $assocArgs
     */
    public function put(array $args, array $assocArgs): void
    {
        $title = $this->title($args[0]);
        $file = $args[1] ?? null;
        $text = $file === null ? stream_get_contents(STDIN) : @file_get_contents($file);
        if ($text === false) {
            Runner::error("Can't read '$file'.");
        }
        $page = $this->services()->getWikiPageFactory()->newFromTitle($title);
        $existed = $page->exists();
        if (!empty($assocArgs['create-only']) && $existed) {
            Runner::error("Page '{$title->getPrefixedText()}' already exists.");
        }
        if (($assocArgs['create'] ?? true) === false && !$existed) {
            $this->missing($title);
        }
        $content = $this->services()->getContentHandlerFactory()
            ->getContentHandler($page->getContentModel())
            ->unserializeContent($text);

        $updater = $this->services()->getPageUpdaterFactory()->newPageUpdater($page, $this->performer());
        $updater->setContent(SlotRecord::MAIN, $content);
        $revision = $updater->saveRevision(
            CommentStoreComment::newUnsavedComment((string) ($assocArgs['summary'] ?? '')),
            EDIT_AUTOSUMMARY
                | (!empty($assocArgs['minor']) ? EDIT_MINOR : 0)
                | (!empty($assocArgs['create-only']) ? EDIT_NEW : 0)
                | (($assocArgs['create'] ?? true) === false ? EDIT_UPDATE : 0)
        );
        $this->check($updater->getStatus());
        $name = $title->getPrefixedText();
        if ($revision === null) {
            Runner::warning("No change: '$name' already has this text.");
        }
        if (!empty($assocArgs['porcelain'])) {
            Runner::line((string) $page->getId());
            return;
        }
        if ($revision !== null) {
            $action = $existed ? 'Updated' : 'Created';
            Runner::success("$action page '$name' (revision {$revision->getId()}).");
        }
    }

    /**
     * Lists pages.
     *
     * Pages are sorted by namespace, then title. Subcategories and files in a category are pages
     * in the Category (14) and File (6) namespaces, so --namespace picks them out.
     *
     * ## OPTIONS
     *
     * [--namespace=<namespace>]
     * : Namespace number or name. All namespaces if left out.
     *
     * [--prefix=<prefix>]
     * : Only titles that start with this, without the namespace.
     *
     * [--category=<category>]
     * : Only pages in this category, e.g. Fruit or Category:Fruit. The prefix can be in the
     * wiki's language.
     *
     * [--limit=<n>]
     * : List at most this many pages.
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
     *     mw page list --category=Fruit --namespace=Category --field=title
     *     mw page list --limit=10
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function list(array $args, array $assocArgs): void
    {
        $db = $this->services()->getConnectionProvider()->getReplicaDatabase();
        $query = $this->services()->getPageStore()->newSelectQueryBuilder($db)
            ->orderBy(['page_namespace', 'page_title'])
            ->caller(__METHOD__);
        $this->applyLimit($query, $assocArgs);
        $namespace = isset($assocArgs['namespace'])
            ? $this->namespace((string) $assocArgs['namespace'])
            : null;
        if ($namespace !== null) {
            $query->whereNamespace($namespace);
        }
        if (isset($assocArgs['prefix'])) {
            $prefix = (string) $assocArgs['prefix'];
            $query->where($this->titlePrefix($db, 'page_title', $prefix, $namespace ?? NS_MAIN));
        }
        if (isset($assocArgs['category'])) {
            // getMembers() hides the categorylinks schema change after 1.43. It only collects the IDs,
            // so the query above still sorts, filters and limits in the database.
            $ids = [];
            $category = Category::newFromTitle($this->category((string) $assocArgs['category']));
            foreach ($category->getMembers() as $member) {
                $ids[] = $member->getArticleID();
            }
            // No page has ID 0, and an empty list would be invalid SQL.
            $query->where(['page_id' => $ids ?: [0]]);
        }

        $formatter = $this->services()->getTitleFormatter();
        $rows = [];
        foreach ($query->fetchPageRecords() as $page) {
            $rows[] = [
                'id' => $page->getId(),
                'title' => $formatter->getPrefixedText($page),
                'namespace' => $page->getNamespace(),
                'redirect' => $page->isRedirect(),
            ];
        }
        Formatter::items($rows, $assocArgs, ['id', 'title']);
    }

    /**
     * Lists the categories of a page.
     *
     * Categories are sorted by name. Hidden categories are included.
     *
     * ## OPTIONS
     *
     * <title>
     * : Page title.
     *
     * [--limit=<n>]
     * : List at most this many categories.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, ids or count.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show: title (the category's title, as in `mw category list`).
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw page category list Main_Page
     *     mw page category list Apple --field=title
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function categoryList(array $args, array $assocArgs): void
    {
        $limit = $this->limit($assocArgs);
        $title = $this->title($args[0]);
        if (!$title->exists()) {
            $this->missing($title);
        }
        $categories = [];
        foreach ($this->services()->getWikiPageFactory()->newFromTitle($title)->getCategories() as $category) {
            $categories[] = $category->getText();
        }
        sort($categories);
        $rows = [];
        // getCategories() takes no limit, and a page has few categories.
        foreach (array_slice($categories, 0, $limit) as $category) {
            $rows[] = ['title' => $category];
        }
        Formatter::items($rows, $assocArgs, ['title']);
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
     * @param array<string,string|bool> $assocArgs
     */
    public function delete(array $args, array $assocArgs): void
    {
        $titles = $this->existing($args);
        $question = count($titles) === 1
            ? "Delete '{$titles[0]->getPrefixedText()}'?"
            : 'Delete ' . count($titles) . ' pages?';
        Runner::confirm($question, $assocArgs);
        $performer = $this->performer();
        $factory = $this->services()->getDeletePageFactory();
        foreach ($titles as $title) {
            $delete = $factory->newDeletePage($title->toPageIdentity(), $performer);
            $this->check($delete->deleteUnsafe((string) ($assocArgs['reason'] ?? '')));
            Runner::success("Deleted '{$title->getPrefixedText()}'.");
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
     * @param array<string,string|bool> $assocArgs
     */
    public function move(array $args, array $assocArgs): void
    {
        $from = $this->title($args[0]);
        $to = $this->title($args[1]);
        $move = $this->services()->getMovePageFactory()->newMovePage($from, $to);
        $this->check($move->isValidMove());
        $this->check($move->move(
            $this->performer(),
            (string) ($assocArgs['reason'] ?? ''),
            ($assocArgs['redirect'] ?? true) !== false
        ));
        Runner::success("Moved '{$from->getPrefixedText()}' to '{$to->getPrefixedText()}'.");
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
     * @param array<string,string|bool> $assocArgs
     */
    public function protect(array $args, array $assocArgs): void
    {
        $title = $this->existing([$args[0]])[0];
        if (!isset($assocArgs['edit']) && !isset($assocArgs['move'])) {
            Runner::error('Pass --edit or --move.');
        }
        $input = (string) ($assocArgs['expiry'] ?? 'infinity');
        $expiry = in_array($input, ExpiryDef::INFINITY_VALS, true) ? 'infinity' : strtotime($input);
        if ($expiry === false || ($expiry !== 'infinity' && $expiry <= time())) {
            Runner::error(
                "Invalid expiry '$input'. Use a future time like '1 week' or 'infinite'."
            );
        }

        [$limit, $expiries] = $this->restrictions($title, $assocArgs, $expiry);
        $cascade = $this->services()->getRestrictionStore()->areRestrictionsCascading($title);
        $page = $this->services()->getWikiPageFactory()->newFromTitle($title);
        $this->check($page->doUpdateRestrictions(
            $limit,
            $expiries,
            $cascade,
            (string) ($assocArgs['reason'] ?? ''),
            $this->performer()
        ));
        $now = array_map(
            static fn (string $action, string $level): string => "$action=" . ($level ?: 'all'),
            array_keys($limit),
            $limit
        );
        Runner::success("Protected '{$title->getPrefixedText()}': " . implode(', ', $now) . '.');
    }

    /**
     * The levels and expiries for doUpdateRestrictions(). It replaces all restrictions, so the
     * ones without an option are passed on unchanged.
     *
     * @param array<string,string|bool> $assocArgs
     * @return array{0: array<string,string>, 1: array<string,string|int|null>}
     */
    private function restrictions(Title $title, array $assocArgs, string|int $expiry): array
    {
        $config = $this->services()->getMainConfig();
        $levels = array_diff($config->get(MainConfigNames::RestrictionLevels), ['']);
        $store = $this->services()->getRestrictionStore();
        $limit = [];
        $expiries = [];
        foreach ($store->listApplicableRestrictionTypes($title) as $action) {
            $level = $assocArgs[$action] ?? null;
            if ($level === null) {
                $limit[$action] = implode(',', $store->getRestrictions($title, $action));
                $expiries[$action] = $store->getRestrictionExpiry($title, $action);
                continue;
            }
            if (!is_string($level) || ($level !== 'all' && !in_array($level, $levels, true))) {
                $allowed = implode(', ', $levels);
                Runner::error("Invalid --$action level '$level'. Use all, $allowed.");
            }
            $limit[$action] = $level === 'all' ? '' : $level;
            $expiries[$action] = $expiry;
        }
        return [$limit, $expiries];
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
     * @param array<string,string|bool> $assocArgs
     */
    public function purge(array $args, array $assocArgs): void
    {
        foreach ($this->existing($args) as $title) {
            $name = $title->getPrefixedText();
            $purged = $this->services()->getWikiPageFactory()->newFromTitle($title)->doPurge();
            $stopped = "A hook stopped purging '$name'.";
            $purged ? Runner::success("Purged '$name'.") : Runner::warning($stopped);
        }
    }

    private function title(string $name): Title
    {
        $title = $this->services()->getTitleFactory()->newFromText($name);
        if ($title === null || !$title->canExist()) {
            Runner::error("Invalid title '$name'.");
        }
        return $title;
    }

    /**
     * Titles for these names, duplicates removed. Fails before anything changes if a page
     * doesn't exist.
     *
     * @param string[] $names
     * @return list<Title>
     */
    private function existing(array $names): array
    {
        $titles = [];
        foreach ($names as $name) {
            $title = $this->title($name);
            $titles[$title->getPrefixedText()] = $title;
        }
        $missing = array_keys(array_filter($titles, static fn (Title $title): bool => !$title->exists()));
        if ($missing) {
            $list = "'" . implode("', '", $missing) . "'";
            Runner::error(
                count($missing) === 1 ? "Page $list doesn't exist." : "Pages $list don't exist."
            );
        }
        return array_values($titles);
    }

    private function missing(Title $title): never
    {
        Runner::error("Page '{$title->getPrefixedText()}' doesn't exist.");
    }

    /**
     * Fails with MediaWiki's message if the status isn't OK.
     *
     * A template, not StatusValue<mixed>: StatusValue only became generic after 1.43.
     *
     * @template T of StatusValue
     * @param T $status
     */
    private function check(StatusValue $status): void
    {
        if (!$status->isOK()) {
            Runner::error($this->statusText($status));
        }
    }
}
