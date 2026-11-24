<?php

namespace MwCli;

/**
 * Reads the docblock of a command method: summary, "## OPTIONS" tokens
 * (`<arg>`, `[<arg>...]`, `--key=<value>`, `[--flag]`, `[--no-flag]`, `[--<field>=<value>]`) and the text for help.
 * Lines from the first @-tag on (e.g. @param) are left out.
 *
 * @phpstan-type SynopsisData array{
 *     summary: string,
 *     text: string,
 *     synopsis: string,
 *     positional: list<array{name: string, optional: bool, repeating: bool}>,
 *     assoc: array<string,bool>,
 *     valued: list<string>,
 *     generic: bool
 * }
 */
class Synopsis {
	/**
	 * @return SynopsisData The assoc key maps option name to "required".
	 */
	public static function parse( string $doc ): array {
		$lines = preg_replace( '#^\s*(/\*\*|\*/|\* ?)#', '', explode( "\n", $doc ) ) ?? [];
		foreach ( $lines as $i => $line ) {
			if ( str_starts_with( $line, '@' ) ) {
				$lines = array_slice( $lines, 0, $i );
				break;
			}
		}
		$text = trim( implode( "\n", $lines ) );
		$out = [
			'summary' => trim( strtok( $text, "\n" ) ?: '' ),
			'text' => $text,
			'synopsis' => '',
			'positional' => [],
			'assoc' => [],
			'valued' => [],
			'generic' => false,
		];
		$tokens = [];
		foreach ( $lines as $line ) {
			$token = '/^(\[)?(<([\w-]+)>|--([\w-]+)(=<[\w-]+>)?|--<[\w-]+>=<[\w-]+>)(\.\.\.)?(\])?$/';
			if ( !preg_match( $token, trim( $line ), $m ) ) {
				continue;
			}
			$tokens[] = trim( $line );
			$optional = $m[1] === '[';
			if ( ( $m[3] ?? '' ) !== '' ) {
				$out['positional'][] = [
					'name' => $m[3],
					'optional' => $optional,
					'repeating' => ( $m[6] ?? '' ) !== '',
				];
			} elseif ( ( $m[4] ?? '' ) !== '' ) {
				// Runner::parseArgv() reads --no-x as x=false.
				$out['assoc'][str_starts_with( $m[4], 'no-' ) ? substr( $m[4], 3 ) : $m[4]] = !$optional;
				if ( ( $m[5] ?? '' ) !== '' ) {
					$out['valued'][] = $m[4];
				}
			} else {
				$out['generic'] = true;
			}
		}
		$out['synopsis'] = implode( ' ', $tokens );
		return $out;
	}

	/**
	 * @param SynopsisData $synopsis
	 * @param string[] $args
	 * @param array<string,string|bool> $assoc
	 * @return string|null What's wrong with the arguments, or null if they fit.
	 */
	public static function validate( array $synopsis, array $args, array $assoc ): ?string {
		$required = count( array_filter( $synopsis['positional'], static fn ( $p ) => !$p['optional'] ) );
		$max = in_array( true, array_column( $synopsis['positional'], 'repeating' ), true )
			? PHP_INT_MAX
			: count( $synopsis['positional'] );
		if ( count( $args ) < $required ) {
			$missing = array_slice( $synopsis['positional'], count( $args ), $required - count( $args ) );
			$missing = array_column( $missing, 'name' );
			return 'Missing argument: ' . implode( ', ', $missing ) . '.';
		}
		if ( count( $args ) > $max ) {
			return 'Too many positional arguments: ' . implode( ' ', array_slice( $args, $max ) ) . '.';
		}
		foreach ( $synopsis['assoc'] as $name => $isRequired ) {
			if ( $isRequired && !isset( $assoc[$name] ) ) {
				return "Missing --$name.";
			}
		}
		foreach ( $synopsis['assoc'] as $name => $isRequired ) {
			$valued = in_array( $name, $synopsis['valued'], true );
			if ( isset( $assoc[$name] ) && $valued === is_bool( $assoc[$name] ) ) {
				return $valued ? "--$name needs a value." : "--$name doesn't take a value.";
			}
		}
		$unknown = array_diff( array_keys( $assoc ), array_keys( $synopsis['assoc'] ) );
		if ( $unknown && !$synopsis['generic'] ) {
			return 'Unknown parameter: --' . implode( ', --', $unknown ) . '.';
		}
		return null;
	}
}
