<?php

declare(strict_types=1);

namespace MwCli\Command;

use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use stdClass;

/**
 * Lists pages that link to a page.
 */
class Backlinks extends Command
{
    /** Links table and its "from" column. */
    protected const TABLE = 'pagelinks';
    protected const FROM = 'pl_from';
    /** Namespace of a target given without one. */
    protected const DEFAULT_NAMESPACE = NS_MAIN;

    /**
     * Lists pages that link to a page.
     *
     * ## OPTIONS
     *
     * <title>
     * : The page that is linked to.
     *
     * [--namespace=<ns>]
     * : Only list pages in this namespace: a number (0 for main) or a name.
     *
     * [--limit=<n>]
     * : List at most this many pages.
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
     *     mw backlinks Main_Page
     *     mw backlinks "Help:Contents" --namespace=Help --field=title
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $target = $this->services()->getTitleFactory()->newFromText($args[0], static::DEFAULT_NAMESPACE);
        // Interwiki and special pages have no link table entries, and a bare "#fragment" names no page.
        if (!$target || $target->isExternal() || $target->getNamespace() < 0 || $target->getDBkey() === '') {
            Runner::error("Invalid title '$args[0]'.");
        }
        $query = $this->services()->getConnectionProvider()->getReplicaDatabase()->newSelectQueryBuilder()
            ->select(['page_id', 'page_namespace', 'page_title'])
            ->from(static::TABLE)
            ->join('page', null, 'page_id = ' . static::FROM)
            ->where($this->services()->getLinksMigration()->getLinksConditions(static::TABLE, $target))
            ->orderBy(['page_namespace', 'page_title'])
            ->caller(__METHOD__);
        $this->applyLimit($query, $assocArgs);
        if (isset($assocArgs['namespace'])) {
            $namespace = $this->namespace((string) $assocArgs['namespace']);
            $query->andWhere([static::FROM . '_namespace' => $namespace]);
        }

        $formatter = $this->services()->getTitleFormatter();
        $rows = [];
        foreach ($query->fetchResultSet() as $row) {
            /** @var stdClass $row */
            $rows[] = [
                'id' => (int) $row->page_id,
                'title' => $formatter->formatTitle((int) $row->page_namespace, $row->page_title),
                'namespace' => (int) $row->page_namespace,
            ];
        }
        Formatter::items($rows, $assocArgs, ['id', 'title']);
    }
}
