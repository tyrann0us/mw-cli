<?php

namespace MwCli;

use ReflectionClass;
use ReflectionMethod;

/**
 * Parses argv, applies config and aliases, then either builds the SSH command for a remote
 * run or dispatches to a command class.
 */
class Runner {
	public const VERSION = '0.1.0';
	public const GLOBAL_FLAGS = [ 'path', 'ssh', 'user', 'quiet' ];

	/** @var array<string,class-string<Command>> */
	public array $commands = [];
	/** @var string[] Positional arguments, command and subcommand included. */
	public array $args = [];
	/** @var array<string,string|bool> Command options. */
	public array $assoc = [];
	/** @var array<string,string|bool> Global flags after config and alias were applied. */
	public array $global = [];
	/** @var string[] Config files that were read. */
	public array $configFiles = [];

	private static bool $quiet = false;
	private ?string $alias = null;
	/** @var string[] argv without the alias, for forwarding over SSH. */
	private array $tokens = [];
	private string $cwd;
	private ?string $home;

	/**
	 * @param string[] $argv
	 */
	public function __construct( array $argv, string $cwd, ?string $home ) {
		$this->cwd = $cwd;
		$this->home = $home;
		foreach ( glob( __DIR__ . '/Command/*.php' ) ?: [] as $file ) {
			$class = __NAMESPACE__ . '\\Command\\' . basename( $file, '.php' );
			if ( is_subclass_of( $class, Command::class ) ) {
				$this->commands[self::kebab( basename( $file, '.php' ) )] = $class;
			}
		}

		// The alias is the first positional token, so global flags may come before it.
		foreach ( $argv as $i => $token ) {
			if ( !str_starts_with( $token, '--' ) ) {
				if ( str_starts_with( $token, '@' ) ) {
					$this->alias = $token;
					array_splice( $argv, $i, 1 );
				}
				break;
			}
		}
		$this->tokens = $argv;
		[ $this->args, $assoc ] = self::parseArgv( $argv );

		$flags = array_intersect_key( $assoc, array_flip( self::GLOBAL_FLAGS ) );
		$this->assoc = array_diff_key( $assoc, $flags );

		[ $config, $this->configFiles ] = Config::load( $cwd, $home );
		$this->global = Config::resolve( $config, $this->alias, $flags );
		self::$quiet = !empty( $this->global['quiet'] );
	}

	/**
	 * --key=value, --flag (true), --no-flag (false), everything after -- is positional.
	 *
	 * @param string[] $argv
	 * @return array{0: string[], 1: array<string,string|bool>}
	 */
	public static function parseArgv( array $argv ): array {
		$args = [];
		$assoc = [];
		while ( $argv ) {
			$token = array_shift( $argv );
			if ( $token === '--' ) {
				array_push( $args, ...$argv );
				break;
			}
			if ( !preg_match( '/^--([^=]+)(?:=(.*))?$/s', $token, $m ) ) {
				$args[] = $token;
			} elseif ( isset( $m[2] ) ) {
				$assoc[$m[1]] = $m[2];
			} elseif ( str_starts_with( $m[1], 'no-' ) ) {
				$assoc[substr( $m[1], 3 )] = false;
			} else {
				$assoc[$m[1]] = true;
			}
		}
		return [ $args, $assoc ];
	}

	/**
	 * The ssh command line that runs this invocation on the remote host, or null for a local run.
	 */
	public function sshCommand(): ?string {
		$ssh = $this->flag( 'ssh' );
		if ( $ssh === null ) {
			return null;
		}
		// ponytail: [user@]host[:port][/path] only; add docker:/vagrant: schemes when asked.
		if ( !preg_match( '#^(?:([^@/]+)@)?([^-:/~@][^:/~@]*)(?::(\d+))?([/~].*)?$#', $ssh, $m ) ) {
			self::error( "Invalid --ssh value '$ssh'. Expected [user@]host[:port][/path]." );
		}
		$target = ( $m[1] !== '' ? "$m[1]@" : '' ) . $m[2];
		$path = ( $m[4] ?? '' ) !== '' ? $m[4] : $this->flag( 'remote-path' );

		// Flags given on the command line are forwarded as-is and win over the alias.
		// Tokens after "--" belong to the command (e.g. a maintenance script), not to mw.
		$end = array_search( '--', $this->tokens, true );
		$end = is_int( $end ) ? $end : count( $this->tokens );
		$own = array_slice( $this->tokens, 0, $end );
		$forward = [ ...preg_grep( '/^--ssh(=|$)/', $own, PREG_GREP_INVERT ) ?: [], ...array_slice( $this->tokens, $end ) ];
		foreach ( [ 'path' => $path, 'user' => $this->flag( 'remote-user' ) ] as $key => $value ) {
			if ( $value !== null && !preg_grep( "/^--$key(=|$)/", $own ) ) {
				array_unshift( $forward, "--$key=$value" );
			}
		}

		$remote = implode( ' ', array_map( 'escapeshellarg', [ 'mw', ...$forward ] ) );
		return 'ssh ' . ( stream_isatty( STDOUT ) ? '-t' : '-T' )
			. ( ( $m[3] ?? '' ) !== '' ? " -p $m[3]" : '' )
			. ' -- ' . escapeshellarg( $target ) . ' ' . escapeshellarg( $remote );
	}

	public function needsWiki(): bool {
		[ $class, $method ] = $this->resolve( $this->args );
		return $class::NEEDS_WIKI && $method !== null && empty( $this->assoc['help'] );
	}

	/**
	 * MediaWiki root from --path, or the nearest parent of the cwd that is one.
	 */
	public function wikiPath(): string {
		$path = $this->flag( 'path' );
		if ( $path !== null ) {
			if ( $this->home !== null && preg_match( '#^~(/|$)#', $path ) ) {
				$path = $this->home . substr( $path, 1 );
			}
			$path = str_starts_with( $path, '/' ) ? $path : "$this->cwd/$path";
			if ( !self::isWiki( $path ) ) {
				self::error( "No MediaWiki installation found in '$path'." );
			}
		} else {
			for ( $path = $this->cwd; !self::isWiki( $path ); $path = dirname( $path ) ) {
				if ( dirname( $path ) === $path ) {
					self::error( 'This does not seem to be a MediaWiki installation. Pass --path=<path> or run mw inside one.' );
				}
			}
		}
		$path = realpath( $path ) ?: $path;

		preg_match( "/define\( 'MW_VERSION', '([^']+)'/", (string)file_get_contents( "$path/includes/Defines.php" ), $m );
		if ( version_compare( $m[1] ?? '0', '1.43', '<' ) ) {
			self::error( 'mw needs MediaWiki 1.43 or later, found ' . ( $m[1] ?? 'unknown' ) . '.' );
		}
		return $path;
	}

	/**
	 * A global flag. Config::resolve() made sure path, ssh, user and their remote- forms are strings.
	 */
	public function flag( string $key ): ?string {
		$value = $this->global[$key] ?? null;
		return is_string( $value ) ? $value : null;
	}

	private static function isWiki( string $dir ): bool {
		return is_file( "$dir/maintenance/run.php" ) && is_file( "$dir/includes/Defines.php" );
	}

	/**
	 * Dispatches to the command. Returns the exit code.
	 */
	public function run(): int {
		try {
			[ $command, $method, $args ] = $this->resolve( $this->args );
			if ( $method === null || !empty( $this->assoc['help'] ) ) {
				( new Command\Help( $this ) )->__invoke( array_slice( $this->args, 0, $method === '__invoke' ? 1 : 2 ), [] );
				return 0;
			}
			$this->validate();
			( new $command( $this ) )->$method( $args, $this->assoc );
			return 0;
		} catch ( ExitException $e ) {
			return self::report( $e );
		}
	}

	/**
	 * Checks args and options against the method's docblock. bin/mw calls it before loading
	 * MediaWiki, so usage errors don't need a wiki.
	 */
	public function validate(): void {
		[ $command, $method, $args ] = $this->resolve( $this->args );
		if ( $method === null || !empty( $this->assoc['help'] ) ) {
			return;
		}
		$doc = ( new ReflectionMethod( $command, $method ) )->getDocComment() ?: '';
		$problem = Synopsis::validate( Synopsis::parse( $doc ), $args, $this->assoc );
		if ( $problem !== null ) {
			self::error( $problem . "\nusage: " . $this->usage( $command, $method ) );
		}
	}

	/**
	 * The command line after the command and subcommand, as typed, minus mw's global flags.
	 * For passing arguments on unchanged, e.g. repeated options.
	 *
	 * @return string[]
	 */
	public function rawArgs(): array {
		[ , $method ] = $this->resolve( $this->args );
		$skip = $method === '__invoke' ? 1 : 2;
		$out = [];
		foreach ( $this->tokens as $i => $token ) {
			if ( $token === '--' ) {
				// "--" only ends mw's own flags; what follows is passed on as-is.
				return [ ...$out, ...array_slice( $this->tokens, $i + 1 ) ];
			}
			if ( preg_match( '/^--(' . implode( '|', self::GLOBAL_FLAGS ) . ')(=|$)/', $token ) ) {
				continue;
			}
			if ( $skip > 0 && !str_starts_with( $token, '--' ) ) {
				$skip--;
				continue;
			}
			$out[] = $token;
		}
		return $out;
	}

	/**
	 * @param string[] $args
	 * @return array{0: class-string<Command>, 1: ?string, 2: string[]} Class, method (null: show help), args.
	 */
	public function resolve( array $args ): array {
		$name = $args[0] ?? 'help';
		$class = $this->commands[$name] ?? null;
		if ( $class === null ) {
			self::error( "'$name' is not a registered mw command. See 'mw help'." );
		}
		if ( method_exists( $class, '__invoke' ) ) {
			return [ $class, '__invoke', array_slice( $args, 1 ) ];
		}
		$sub = $args[1] ?? null;
		if ( $sub === null ) {
			return [ $class, null, [] ];
		}
		$method = lcfirst( str_replace( '-', '', ucwords( $sub, '-' ) ) );
		if ( !in_array( $method, self::subcommands( $class ), true ) ) {
			self::error( "'$sub' is not a registered subcommand of '$name'. See 'mw help $name'." );
		}
		return [ $class, $method, array_slice( $args, 2 ) ];
	}

	/**
	 * @param class-string $class
	 * @return string[] Public methods that are subcommands.
	 */
	public static function subcommands( string $class ): array {
		$methods = [];
		foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $m ) {
			if ( !$m->isStatic() && !$m->isConstructor() && $m->getDeclaringClass()->getName() === $class ) {
				$methods[] = $m->getName();
			}
		}
		return $methods;
	}

	public function usage( string $class, string $method ): string {
		$name = array_search( $class, $this->commands, true );
		$sub = $method === '__invoke' ? '' : ' ' . self::kebab( $method );
		$synopsis = Synopsis::parse( ( new ReflectionMethod( $class, $method ) )->getDocComment() ?: '' )['synopsis'];
		return trim( "mw $name$sub $synopsis" );
	}

	/**
	 * SearchReplace or searchReplace to search-replace.
	 */
	public static function kebab( string $name ): string {
		return strtolower( (string)preg_replace( '/(?<!^)[A-Z]/', '-$0', $name ) );
	}

	public static function report( ExitException $e ): int {
		if ( $e->getMessage() !== '' ) {
			fwrite( STDERR, 'Error: ' . $e->getMessage() . "\n" );
		}
		return $e->getCode();
	}

	public static function line( string $message = '' ): void {
		echo $message, "\n";
	}

	public static function success( string $message ): void {
		if ( !self::$quiet ) {
			echo "Success: $message\n";
		}
	}

	public static function warning( string $message ): void {
		if ( !self::$quiet ) {
			fwrite( STDERR, "Warning: $message\n" );
		}
	}

	public static function error( string $message, int $code = 1 ): never {
		throw new ExitException( $message, $code );
	}

	/**
	 * Asks for a yes/no answer unless --yes was passed. Exits quietly with 0 on anything but "y".
	 *
	 * @param string $question
	 * @param array<string,string|bool> $assoc
	 */
	public static function confirm( string $question, array $assoc ): void {
		if ( !empty( $assoc['yes'] ) ) {
			return;
		}
		echo "$question [y/n] ";
		$answer = fgets( STDIN );
		if ( $answer === false ) {
			self::error( 'No answer on stdin. Pass --yes to run without confirmation.' );
		}
		if ( strtolower( trim( $answer ) ) !== 'y' ) {
			throw new ExitException( '', 0 );
		}
	}
}
