<?php

declare(strict_types=1);

namespace MwCli\Command;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserNameUtils;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use stdClass;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Lists recent changes.
 */
class RecentChanges extends Command
{
    private const TYPES = ['edit', 'new', 'log'];
    /** LogPage::DELETED_ACTION, which is \LogPage in 1.43 and MediaWiki\Logging\LogPage later. */
    private const DELETED_ACTION = 1;

    /**
     * Lists the latest entries from recent changes, newest first.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : How many entries to list. Default: 50.
     *
     * [--namespace=<ns>]
     * : Only list changes in this namespace: a number (0 for main) or a name.
     *
     * [--by=<username>]
     * : Only list changes by this user.
     *
     * [--type=<type>]
     * : Only list this type of change: edit, new or log.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, ids or count.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show: id, timestamp, type, title, namespace, user, comment,
     * revision, action. Default: id, timestamp, type, title, user, comment.
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw recent-changes --limit=10
     *     mw recent-changes --by=Admin --type=new --field=title
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $dbr = $this->services()->getConnectionProvider()->getReplicaDatabase();
        $query = $dbr->newSelectQueryBuilder()
            ->select([
                'rc_id', 'rc_timestamp', 'rc_source', 'rc_namespace', 'rc_title', 'rc_this_oldid',
                'rc_log_type', 'rc_log_action', 'rc_deleted', 'actor_name', 'comment_text',
            ])
            ->from('recentchanges')
            ->join('actor', null, 'actor_id = rc_actor')
            ->join('comment', null, 'comment_id = rc_comment_id')
            ->orderBy(['rc_timestamp', 'rc_id'], 'DESC')
            ->limit($this->limit($assocArgs) ?? 50)
            ->caller(__METHOD__);
        $this->filter($query, $dbr, $assocArgs);

        $formatter = $this->services()->getTitleFormatter();
        $utc = new DateTimeZone('UTC');
        $rows = [];
        foreach ($query->fetchResultSet() as $row) {
            /** @var stdClass $row */
            $rows[] = $this->row($row, $formatter, $utc);
        }
        $defaultFields = ['id', 'timestamp', 'type', 'title', 'user', 'comment'];
        Formatter::items($rows, $assocArgs, $defaultFields);
    }

    /**
     * Adds the conditions for --namespace, --by and --type.
     *
     * @param array<string,string|bool> $assocArgs
     */
    private function filter(
        SelectQueryBuilder $query,
        IReadableDatabase $dbr,
        array $assocArgs
    ): void {

        if (isset($assocArgs['namespace'])) {
            // Skip log entries with a hidden target, or the filter would reveal their namespace.
            $actionShown = $dbr->bitAnd('rc_deleted', self::DELETED_ACTION) . ' = 0';
            $query->andWhere([
                'rc_namespace' => $this->namespace((string) $assocArgs['namespace']),
                "rc_log_type IS NULL OR $actionShown",
            ]);
        }
        if (isset($assocArgs['by'])) {
            $by = (string) $assocArgs['by'];
            if ($by === '') {
                Runner::error('--by needs a value.');
            }
            $name = $this->services()->getUserNameUtils()
                ->getCanonical($by, UserNameUtils::RIGOR_NONE) ?: $by;
            // Like MediaWiki's own lists, don't reveal who made changes whose user is hidden.
            $query->andWhere([
                'actor_name' => $name,
                $dbr->bitAnd('rc_deleted', RevisionRecord::DELETED_USER) . ' = 0',
            ]);
        }
        if (isset($assocArgs['type'])) {
            if (!in_array($assocArgs['type'], self::TYPES, true)) {
                Runner::error('Invalid type. Use one of: ' . implode(', ', self::TYPES) . '.');
            }
            $query->andWhere(['rc_source' => 'mw.' . $assocArgs['type']]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function row(stdClass $row, TitleFormatter $formatter, DateTimeZone $utc): array
    {
        $hidden = (int) $row->rc_deleted;
        $hideAction = $row->rc_log_type !== null && $hidden & self::DELETED_ACTION;
        $title = $hideAction
            ? null
            : $formatter->formatTitle((int) $row->rc_namespace, $row->rc_title);
        return [
            'id' => (int) $row->rc_id,
            // PHP reads YmdHis (MySQL, SQLite) and PostgreSQL timestamps, which may carry an
            // offset.
            'timestamp' => (new DateTimeImmutable($row->rc_timestamp, $utc))
                ->setTimezone($utc)->format('Y-m-d H:i:s'),
            // mw.edit, mw.new, mw.log, mw.categorize, or an extension's own source.
            'type' => preg_replace('/^mw\./', '', $row->rc_source),
            // Hidden by revision deletion or suppression.
            'title' => $title,
            'namespace' => $hideAction ? null : (int) $row->rc_namespace,
            'user' => $hidden & RevisionRecord::DELETED_USER ? null : $row->actor_name,
            'comment' => $hidden & RevisionRecord::DELETED_COMMENT ? null : $row->comment_text,
            // A log entry's null revision would point back to its hidden target.
            'revision' => $hideAction ? null : ((int) $row->rc_this_oldid ?: null),
            'action' => $row->rc_log_type === null || $hideAction
                ? null
                : $row->rc_log_type . '/' . $row->rc_log_action,
        ];
    }
}
