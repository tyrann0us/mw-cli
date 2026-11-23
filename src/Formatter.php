<?php

namespace MwCli;

/**
 * Prints rows in the format picked with --format, limited to --fields (or one --field).
 */
class Formatter {
	public const FORMATS = [ 'table', 'json', 'csv', 'yaml', 'ids', 'count' ];
	private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

	/**
	 * A list of records.
	 *
	 * @param array<array<string,mixed>> $rows
	 * @param array<string,string|bool> $assoc
	 * @param string[] $defaultFields Shown unless --fields is passed.
	 */
	public static function items( array $rows, array $assoc, array $defaultFields ): void {
		$fields = self::fields( $assoc, $defaultFields, $rows[array_key_first( $rows )] ?? null );
		$rows = array_map( static fn ( $row ) => self::pick( $row, $fields ), array_values( $rows ) );
		if ( isset( $assoc['field'] ) ) {
			foreach ( $rows as $row ) {
				echo self::scalar( $row[$fields[0]] ), "\n";
			}
			return;
		}

		switch ( self::format( $assoc ) ) {
			case 'json':
				echo json_encode( $rows, self::JSON ), "\n";
				break;
			case 'csv':
				$out = fopen( 'php://output', 'w' ) ?: Runner::error( "Can't write to stdout." );
				fputcsv( $out, $fields, ',', '"', '' );
				foreach ( $rows as $row ) {
					fputcsv( $out, array_map( [ self::class, 'scalar' ], $row ), ',', '"', '' );
				}
				fclose( $out );
				break;
			case 'yaml':
				echo self::yaml( $rows );
				break;
			case 'ids':
				if ( !$fields ) {
					Runner::error( 'There is no field to print.' );
				}
				echo implode( ' ', array_map( [ self::class, 'scalar' ], array_column( $rows, $fields[0] ) ) ), "\n";
				break;
			case 'count':
				echo count( $rows ), "\n";
				break;
			default:
				echo self::table( $fields, $rows );
		}
	}

	/**
	 * A single record. The table format prints it as Field/Value rows.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,string|bool> $assoc
	 */
	public static function item( array $row, array $assoc ): void {
		$fields = self::fields( $assoc, array_keys( $row ), $row );
		$row = self::pick( $row, $fields );
		if ( isset( $assoc['field'] ) ) {
			echo self::scalar( $row[$fields[0]] ), "\n";
			return;
		}
		switch ( self::format( $assoc, [ 'table', 'json', 'yaml' ] ) ) {
			case 'json':
				echo json_encode( $row, self::JSON ), "\n";
				break;
			case 'yaml':
				echo self::yaml( $row );
				break;
			default:
				$pairs = [];
				foreach ( $row as $field => $value ) {
					$pairs[] = [ 'Field' => $field, 'Value' => $value ];
				}
				echo self::table( [ 'Field', 'Value' ], $pairs );
		}
	}

	/**
	 * @param array<string,string|bool> $assoc
	 * @param string[] $allowed
	 */
	private static function format( array $assoc, array $allowed = self::FORMATS ): string {
		$format = $assoc['format'] ?? 'table';
		if ( !is_string( $format ) || !in_array( $format, $allowed, true ) ) {
			Runner::error( 'Invalid format. Use one of: ' . implode( ', ', $allowed ) . '.' );
		}
		return $format;
	}

	/**
	 * @param array<string,string|bool> $assoc
	 * @param string[] $defaults
	 * @param array<string,mixed>|null $sample A row to check the requested fields against.
	 * @return string[]
	 */
	private static function fields( array $assoc, array $defaults, ?array $sample ): array {
		if ( isset( $assoc['field'] ) ) {
			$fields = [ (string)$assoc['field'] ];
		} elseif ( isset( $assoc['fields'] ) ) {
			$fields = array_map( 'trim', explode( ',', (string)$assoc['fields'] ) );
		} else {
			return $defaults;
		}
		$unknown = $sample === null ? [] : array_diff( $fields, array_keys( $sample ) );
		if ( $unknown ) {
			Runner::error( 'Invalid field: ' . implode( ', ', $unknown ) . '.' );
		}
		return $fields;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param string[] $fields
	 * @return array<string,mixed>
	 */
	private static function pick( array $row, array $fields ): array {
		return array_merge( array_fill_keys( $fields, null ), array_intersect_key( $row, array_flip( $fields ) ) );
	}

	private static function scalar( mixed $value ): string {
		return match ( true ) {
			$value === null => '',
			is_bool( $value ) => $value ? 'true' : 'false',
			is_scalar( $value ) => (string)$value,
			default => json_encode( $value, self::JSON ),
		};
	}

	/**
	 * A box table on a terminal, tab-separated values when piped.
	 *
	 * @param string[] $fields
	 * @param array<array<string,mixed>> $rows
	 */
	private static function table( array $fields, array $rows ): string {
		$cells = array_map( static fn ( $row ) => array_map( [ self::class, 'scalar' ], array_values( $row ) ), $rows );
		if ( !stream_isatty( STDOUT ) ) {
			// One record per line: escape what would split it.
			$escape = static fn ( $c ) => strtr( $c, [ '\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r' ] );
			$lines = array_map( static fn ( $r ) => implode( "\t", array_map( $escape, $r ) ), [ $fields, ...$cells ] );
			return implode( "\n", $lines ) . "\n";
		}
		$widths = array_map( 'mb_strwidth', $fields );
		foreach ( $cells as $r ) {
			foreach ( $r as $i => $c ) {
				$widths[$i] = max( $widths[$i], mb_strwidth( $c ) );
			}
		}
		$rule = '+' . implode( '+', array_map( static fn ( $w ) => str_repeat( '-', $w + 2 ), $widths ) ) . "+\n";
		$line = static function ( array $r ) use ( $widths ) {
			$out = '|';
			foreach ( $r as $i => $c ) {
				$out .= ' ' . $c . str_repeat( ' ', $widths[$i] - mb_strwidth( $c ) ) . ' |';
			}
			return "$out\n";
		};
		return $rule . $line( $fields ) . $rule . implode( '', array_map( $line, $cells ) ) . $rule;
	}

	/**
	 * Block YAML. Values are written as JSON scalars, which YAML reads unchanged.
	 *
	 * @param array<string,mixed>|list<array<string,mixed>> $data
	 */
	private static function yaml( array $data ): string {
		$out = "---\n";
		$map = static function ( array $row, string $indent ): string {
			$s = '';
			foreach ( $row as $k => $v ) {
				$s .= "$indent$k: " . json_encode( $v, self::JSON ) . "\n";
			}
			return $s;
		};
		if ( !array_is_list( $data ) ) {
			return $out . $map( $data, '' );
		}
		foreach ( $data as $row ) {
			$out .= "-\n" . $map( $row, '  ' );
		}
		return $out;
	}
}
