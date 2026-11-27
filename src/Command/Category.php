<?php

declare(strict_types=1);

namespace MwCli\Command;

use MwCli\Command;
use MwCli\Formatter;
use stdClass;

/**
 * Lists categories.
 */
class Category extends Command
{
    /**
     * Lists the categories that have members, sorted by name.
     *
     * The counts come from MediaWiki's category table, as on Special:Categories. Newer
     * MediaWiki versions update them in a job, so they can lag behind recent edits until the
     * job queue has run. A category can have members without having a page.
     *
     * ## OPTIONS
     *
     * [--prefix=<prefix>]
     * : Only categories whose name starts with this, without the namespace.
     *
     * [--limit=<n>]
     * : List at most this many categories.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, ids or count.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show: title, members, pages, subcats, files. members is the
     * sum of the other three. Default: title, pages, subcats, files.
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw category list
     *     mw category list --prefix=Fruit --field=title
     *     mw category list --limit=10 --format=csv
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function list(array $args, array $assocArgs): void
    {
        $db = $this->services()->getConnectionProvider()->getReplicaDatabase();
        $query = $db->newSelectQueryBuilder()
            ->select(['cat_title', 'cat_pages', 'cat_subcats', 'cat_files'])
            ->from('category')
            ->where('cat_pages > 0')
            ->orderBy('cat_title')
            ->caller(__METHOD__);
        $limit = $this->limit($assocArgs);
        if ($limit !== null) {
            $query->limit($limit);
        }
        if (isset($assocArgs['prefix'])) {
            $prefix = (string) $assocArgs['prefix'];
            $query->andWhere($this->titlePrefix($db, 'cat_title', $prefix, NS_CATEGORY));
        }

        $rows = [];
        foreach ($query->fetchResultSet() as $row) {
            /** @var stdClass $row */
            // cat_pages counts all members, like Category::getMemberCount().
            $members = (int) $row->cat_pages;
            $subcats = (int) $row->cat_subcats;
            $files = (int) $row->cat_files;
            $rows[] = [
                'title' => strtr($row->cat_title, '_', ' '),
                'members' => $members,
                // The counts can drift apart, so never below 0, as in Category::getPageCount().
                'pages' => max(0, $members - $subcats - $files),
                'subcats' => $subcats,
                'files' => $files,
            ];
        }
        Formatter::items($rows, $assocArgs, ['title', 'pages', 'subcats', 'files']);
    }
}
