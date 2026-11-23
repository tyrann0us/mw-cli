<?php

namespace MwCli\Command;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserNameUtils;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;

/**
 * Lists recent changes.
 */
class RecentChanges extends Command {
	private const TYPES = [ 'edit', 'new', 'log' ];

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
	 * : revision, action. Default: id, timestamp, type, title, user, comment.
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
	 * @param array<string,string|bool> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$limit = $assoc_args['limit'] ?? '50';
		if ( !is_string( $limit ) || !ctype_digit( $limit ) || (int)$limit < 1 ) {
			Runner::error( '--limit must be a positive number.' );
		}
		$query = $this->services()->getConnectionProvider()->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [
				'rc_id', 'rc_timestamp', 'rc_source', 'rc_namespace', 'rc_title', 'rc_this_oldid',
				'rc_log_type', 'rc_log_action', 'rc_deleted', 'actor_name', 'comment_text',
			] )
			->from( 'recentchanges' )
			->join( 'actor', null, 'actor_id = rc_actor' )
			->join( 'comment', null, 'comment_id = rc_comment_id' )
			->orderBy( [ 'rc_timestamp', 'rc_id' ], 'DESC' )
			->limit( (int)$limit )
			->caller( __METHOD__ );
		if ( isset( $assoc_args['namespace'] ) ) {
			$query->andWhere( [ 'rc_namespace' => $this->namespace( $assoc_args['namespace'] ) ] );
		}
		if ( isset( $assoc_args['by'] ) ) {
			$by = (string)$assoc_args['by'];
			$name = $this->services()->getUserNameUtils()->getCanonical( $by, UserNameUtils::RIGOR_NONE ) ?: $by;
			$query->andWhere( [ 'actor_name' => $name ] );
		}
		if ( isset( $assoc_args['type'] ) ) {
			if ( !in_array( $assoc_args['type'], self::TYPES, true ) ) {
				Runner::error( 'Invalid type. Use one of: ' . implode( ', ', self::TYPES ) . '.' );
			}
			$query->andWhere( [ 'rc_source' => 'mw.' . $assoc_args['type'] ] );
		}

		$formatter = $this->services()->getTitleFormatter();
		$utc = new DateTimeZone( 'UTC' );
		$rows = [];
		$res = $query->fetchResultSet();
		while ( $row = $res->fetchObject() ) {
			$hidden = (int)$row->rc_deleted;
			$rows[] = [
				'id' => (int)$row->rc_id,
				// PHP reads YmdHis (MySQL, SQLite) and PostgreSQL timestamps alike.
				'timestamp' => ( new DateTimeImmutable( $row->rc_timestamp, $utc ) )->format( 'Y-m-d\TH:i:s\Z' ),
				// mw.edit, mw.new, mw.log, mw.categorize, or an extension's own source.
				'type' => preg_replace( '/^mw\./', '', $row->rc_source ),
				'title' => $formatter->formatTitle( (int)$row->rc_namespace, $row->rc_title ),
				'namespace' => (int)$row->rc_namespace,
				// Hidden by revision deletion or suppression.
				'user' => $hidden & RevisionRecord::DELETED_USER ? null : $row->actor_name,
				'comment' => $hidden & RevisionRecord::DELETED_COMMENT ? null : $row->comment_text,
				'revision' => (int)$row->rc_this_oldid ?: null,
				'action' => $row->rc_log_type === null ? null : $row->rc_log_type . '/' . $row->rc_log_action,
			];
		}
		Formatter::items( $rows, $assoc_args, [ 'id', 'timestamp', 'type', 'title', 'user', 'comment' ] );
	}
}
