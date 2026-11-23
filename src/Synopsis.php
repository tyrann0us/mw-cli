<?php

namespace MwCli;

/**
 * Reads the docblock of a command method: summary, "## OPTIONS" tokens
 * (`<arg>`, `[<arg>...]`, `--key=<value>`, `[--flag]`, `[--<field>=<value>]`) and the text for help.
 */
class Synopsis {
	/**
	 * @return array{summary: string, text: string, synopsis: string, positional: list<array{name: string, optional: bool, repeating: bool}>, assoc: array<string,bool>, generic: bool}
	 *   assoc maps option name to "required".
	 */
	public static function parse( string $doc ): array {
		$lines = preg_replace( '#^\s*(/\*\*|\*/|\* ?)#', '', explode( "\n", $doc ) );
		$text = trim( implode( "\n", $lines ) );
		$out = [
			'summary' => trim( strtok( $text, "\n" ) ?: '' ),
			'text' => $text,
			'synopsis' => '',
			'positional' => [],
			'assoc' => [],
			'generic' => false,
		];
		$tokens = [];
		foreach ( $lines as $line ) {
			if ( !preg_match( '/^(\[)?(<([\w-]+)>|--([\w-]+)(=<[\w-]+>)?|--<[\w-]+>=<[\w-]+>)(\.\.\.)?(\])?$/', trim( $line ), $m ) ) {
				continue;
			}
			$tokens[] = trim( $line );
			$optional = $m[1] === '[';
			if ( ( $m[3] ?? '' ) !== '' ) {
				$out['positional'][] = [ 'name' => $m[3], 'optional' => $optional, 'repeating' => ( $m[6] ?? '' ) !== '' ];
			} elseif ( ( $m[4] ?? '' ) !== '' ) {
				$out['assoc'][$m[4]] = !$optional;
			} else {
				$out['generic'] = true;
			}
		}
		$out['synopsis'] = implode( ' ', $tokens );
		return $out;
	}

	/**
	 * @return string|null What's wrong with the arguments, or null if they fit.
	 */
	public static function validate( array $synopsis, array $args, array $assoc ): ?string {
		$required = 0;
		$max = 0;
		foreach ( $synopsis['positional'] as $p ) {
			$required += $p['optional'] ? 0 : 1;
			$max = $p['repeating'] || $max === PHP_INT_MAX ? PHP_INT_MAX : $max + 1;
		}
		if ( count( $args ) < $required ) {
			$missing = array_column( array_slice( $synopsis['positional'], count( $args ), $required - count( $args ) ), 'name' );
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
		$unknown = array_diff( array_keys( $assoc ), array_keys( $synopsis['assoc'] ) );
		if ( $unknown && !$synopsis['generic'] ) {
			return 'Unknown parameter: --' . implode( ', --', $unknown ) . '.';
		}
		return null;
	}
}
