<?php

namespace MwCli;

/**
 * Reads ~/.mw/config.ini and the nearest mw.ini, and merges them with an alias and CLI flags.
 *
 * INI instead of YAML because config is read before MediaWiki (and its YAML parser) loads.
 */
class Config {
	/**
	 * @return array{0: array, 1: string[]} Merged config and the files that were read.
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
			// A relative path is relative to the file that sets it, unless it's for an SSH host.
			foreach ( [ null, ...array_keys( array_filter( $ini, 'is_array' ) ) ] as $section ) {
				$values = $section === null ? $ini : $ini[$section];
				if ( is_string( $values['path'] ?? null ) && !isset( $values['ssh'] ) && !preg_match( '#^[/~]#', $values['path'] ) ) {
					$path = dirname( $file ) . '/' . $values['path'];
					if ( $section === null ) {
						$ini['path'] = $path;
					} else {
						$ini[$section]['path'] = $path;
					}
				}
			}
			$config = array_replace_recursive( $config, $ini );
		}
		return [ $config, $files ];
	}

	/**
	 * Applies config, then the alias section, then CLI flags. An alias with "ssh" points to
	 * another host, so its path and user become remote-path and remote-user for that host.
	 */
	public static function resolve( array $config, ?string $alias, array $flags ): array {
		$global = array_intersect_key(
			array_filter( $config, static fn ( $v ) => !is_array( $v ) ),
			array_flip( Runner::GLOBAL_FLAGS )
		);

		if ( $alias !== null ) {
			$section = $config[$alias] ?? null;
			if ( !is_array( $section ) ) {
				Runner::error( "Alias '$alias' not found." );
			}
			$section = array_intersect_key( $section, array_flip( Runner::GLOBAL_FLAGS ) );
			if ( isset( $section['ssh'] ) ) {
				foreach ( [ 'path', 'user' ] as $key ) {
					if ( isset( $section[$key] ) ) {
						$global["remote-$key"] = $section[$key];
						unset( $section[$key] );
					}
				}
			}
			$global = array_replace( $global, $section );
		}

		$global = array_replace( $global, $flags );
		foreach ( [ 'path', 'ssh', 'user', 'remote-path', 'remote-user' ] as $key ) {
			if ( !isset( $global[$key] ) ) {
				continue;
			}
			if ( is_bool( $global[$key] ) || $global[$key] === '' ) {
				Runner::error( "--$key needs a value." );
			}
			$global[$key] = (string)$global[$key];
		}
		return $global;
	}
}
