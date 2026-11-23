<?php

namespace MwCli;

/**
 * Reads ~/.mw/config.ini and the nearest mw.ini, and merges them with an alias and CLI flags.
 *
 * INI instead of YAML because config is read before MediaWiki (and its YAML parser) loads.
 */
class Config {
	/**
	 * @return array{0: array<string,mixed>, 1: string[]} Merged config and the files that were read.
	 */
	public static function load( string $cwd, ?string $home ): array {
		$files = [];
		if ( $home !== null && is_file( "$home/.mw/config.ini" ) ) {
			$files[] = "$home/.mw/config.ini";
		}
		for ( $dir = $cwd; ; $dir = dirname( $dir ) ) {
			if ( is_file( "$dir/mw.ini" ) ) {
				$files[] = "$dir/mw.ini";
				break;
			}
			if ( dirname( $dir ) === $dir ) {
				break;
			}
		}

		$config = [];
		foreach ( $files as $file ) {
			$ini = @parse_ini_file( $file, true, INI_SCANNER_TYPED );
			if ( $ini === false ) {
				Runner::error( "Can't parse config file $file." );
			}
			// Remember where each path was set: a relative one is relative to that file, unless
			// it ends up in an SSH alias, which only resolve() knows after all files are merged.
			foreach ( [ null, ...array_keys( array_filter( $ini, 'is_array' ) ) ] as $section ) {
				if ( $section === null && isset( $ini['path'] ) ) {
					$ini['path-base'] = dirname( $file );
				} elseif ( $section !== null && isset( $ini[$section]['path'] ) ) {
					$ini[$section]['path-base'] = dirname( $file );
				}
			}
			$config = array_replace_recursive( $config, $ini );
		}
		return [ $config, $files ];
	}

	/**
	 * Applies config, then the alias section, then CLI flags. An alias with "ssh" points to
	 * another host, so its path becomes remote-path for that host.
	 *
	 * @param array<string,mixed> $config
	 * @param string|null $alias
	 * @param array<string,string|bool> $flags
	 * @return array<string,string|bool>
	 */
	public static function resolve( array $config, ?string $alias, array $flags ): array {
		$global = array_intersect_key(
			self::rebase( array_filter( $config, static fn ( $v ) => !is_array( $v ) ) ),
			array_flip( Runner::GLOBAL_FLAGS )
		);

		if ( $alias !== null ) {
			$section = $config[$alias] ?? null;
			if ( !is_array( $section ) ) {
				Runner::error( "Alias '$alias' not found." );
			}
			$section = array_intersect_key(
				isset( $section['ssh'] ) ? $section : self::rebase( $section ),
				array_flip( Runner::GLOBAL_FLAGS )
			);
			if ( isset( $section['ssh'] ) ) {
				if ( isset( $section['path'] ) ) {
					$global['remote-path'] = $section['path'];
					unset( $section['path'] );
				}
			}
			$global = array_replace( $global, $section );
		}

		$out = [];
		foreach ( array_replace( $global, $flags ) as $key => $value ) {
			if ( is_bool( $value ) || $value === '' || !is_scalar( $value ) ) {
				Runner::error( "--$key needs a value." );
			} else {
				$out[$key] = (string)$value;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	private static function rebase( array $values ): array {
		$path = $values['path'] ?? null;
		if ( is_string( $path ) && isset( $values['path-base'] ) && !preg_match( '#^[/~]#', $path ) ) {
			$values['path'] = $values['path-base'] . '/' . $path;
		}
		return $values;
	}
}
