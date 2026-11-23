<?php

namespace MwCli\Tests;

use MwCli\ExitException;
use MwCli\Runner;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/mw-cli-test-' . uniqid();
		mkdir( "$this->dir/project/sub", 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	public function testParseArgv(): void {
		[ $args, $assoc ] = Runner::parseArgv( [ 'page', 'get', 'Main Page', '--format=json', '--yes', '--no-color', '--', '--literal' ] );
		$this->assertSame( [ 'page', 'get', 'Main Page', '--literal' ], $args );
		$this->assertSame( [ 'format' => 'json', 'yes' => true, 'color' => false ], $assoc );
	}

	public function testGlobalFlagsAreSeparatedFromCommandOptions(): void {
		$cli = new Runner( [ 'page', 'list', '--user=Admin', '--format=ids' ], $this->dir, null );
		$this->assertSame( 'Admin', $cli->global['user'] );
		$this->assertSame( [ 'format' => 'ids' ], $cli->assoc );
	}

	public function testCommandsAreDiscoveredFromFileNames(): void {
		$cli = new Runner( [], $this->dir, null );
		$this->assertSame( 'MwCli\Command\Help', $cli->commands['help'] );
		$this->assertSame( 'MwCli\Command\Maint', $cli->commands['maint'] );
	}

	public function testResolveSubcommand(): void {
		$cli = new Runner( [], $this->dir, null );
		$this->assertSame( [ 'MwCli\Command\Cli', 'version', [ 'x' ] ], $cli->resolve( [ 'cli', 'version', 'x' ] ) );
		$this->assertSame( [ 'MwCli\Command\Cli', null, [] ], $cli->resolve( [ 'cli' ] ) );
		$this->assertSame( [ 'MwCli\Command\Help', '__invoke', [ 'cli' ] ], $cli->resolve( [ 'help', 'cli' ] ) );
	}

	public function testValidateSkipsHelpAndRejectsBadArgs(): void {
		( new Runner( [ 'cli', 'version', '--nope', '--help' ], $this->dir, null ) )->validate();
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( "Unknown parameter: --nope.\nusage: mw cli version" );
		( new Runner( [ 'cli', 'version', '--nope' ], $this->dir, null ) )->validate();
	}

	public function testQuietSilencesSuccessAndYesSkipsConfirm(): void {
		$this->expectOutputString( "Success: shown\n" );
		new Runner( [ 'cli', 'version' ], $this->dir, null );
		Runner::success( 'shown' );
		new Runner( [ '--quiet', 'cli', 'version' ], $this->dir, null );
		Runner::success( 'hidden' );
		Runner::warning( 'hidden' );
		Runner::confirm( 'Sure?', [ 'yes' => true ] );
	}

	public function testUnknownCommandFails(): void {
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( "'nope' is not a registered mw command" );
		( new Runner( [], $this->dir, null ) )->resolve( [ 'nope' ] );
	}

	public function testRawArgsKeepRepeatedOptionsAndDropGlobalFlags(): void {
		$cli = new Runner( [ '--quiet', 'maint', 'x', '--skip=a', '--user=Bot', '--skip=b', '--', '--user=Admin' ], $this->dir, null );
		$this->assertSame( [ 'x', '--skip=a', '--skip=b', '--user=Admin' ], $cli->rawArgs() );
	}

	public function testNoSshMeansLocalRun(): void {
		$this->assertNull( ( new Runner( [ 'cli', 'version' ], $this->dir, null ) )->sshCommand() );
	}

	public function testSshCommandForwardsArgsAndPath(): void {
		$cli = new Runner( [ 'page', 'get', "It's", '--ssh=deploy@wiki.example.org:2222/var/www/w' ], $this->dir, null );
		$this->assertSame(
			"ssh -T -p 2222 -- 'deploy@wiki.example.org' "
				. escapeshellarg( "'mw' '--path=/var/www/w' 'page' 'get' 'It'\\''s'" ),
			self::ssh( $cli )
		);
	}

	public function testSshAliasAppliesRemotePathAndUser(): void {
		file_put_contents( "$this->dir/project/mw.ini", "path = w\n[@prod]\nssh = wiki.example.org\npath = /srv/w\nuser = Bot\n" );
		$cli = new Runner( [ '@prod', 'cli', 'version' ], "$this->dir/project/sub", null );
		$this->assertSame(
			"ssh -T -- 'wiki.example.org' " . escapeshellarg( "'mw' '--user=Bot' '--path=/srv/w' 'cli' 'version'" ),
			self::ssh( $cli )
		);
	}

	public function testCommandLineFlagsWinOverAlias(): void {
		file_put_contents( "$this->dir/project/mw.ini", "[@prod]\nssh = wiki.example.org\npath = /srv/w\n" );
		$cli = new Runner( [ '@prod', '--path=/other', 'cli', 'version' ], "$this->dir/project", null );
		$this->assertStringContainsString( escapeshellarg( "'mw' '--path=/other' 'cli' 'version'" ), self::ssh( $cli ) );
	}

	public function testSshHostCannotStartWithDash(): void {
		$this->expectException( ExitException::class );
		( new Runner( [ 'cli', 'version', '--ssh=-oProxyCommand=x' ], $this->dir, null ) )->sshCommand();
	}

	public function testWikiPathIsFoundByWalkingUp(): void {
		$wiki = "$this->dir/project";
		mkdir( "$wiki/maintenance" );
		mkdir( "$wiki/includes" );
		touch( "$wiki/maintenance/run.php" );
		file_put_contents( "$wiki/includes/Defines.php", "<?php define( 'MW_VERSION', '1.43.1' );" );
		$this->assertSame( realpath( $wiki ), ( new Runner( [], "$wiki/sub", null ) )->wikiPath() );

		file_put_contents( "$wiki/includes/Defines.php", "<?php define( 'MW_VERSION', '1.42.3' );" );
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'needs MediaWiki 1.43 or later, found 1.42.3' );
		( new Runner( [], "$wiki/sub", null ) )->wikiPath();
	}

	public function testFlagsWithoutValueFail(): void {
		foreach ( [ '--user', '--ssh', '--path' ] as $flag ) {
			try {
				new Runner( [ 'cli', 'info', $flag ], $this->dir, null );
				$this->fail( "$flag without value was accepted" );
			} catch ( ExitException $e ) {
				$this->assertSame( "$flag needs a value.", $e->getMessage() );
			}
		}
	}

	public function testAliasMayFollowGlobalFlags(): void {
		file_put_contents( "$this->dir/project/mw.ini", "[@prod]\nssh = wiki.example.org\n" );
		$cli = new Runner( [ '--quiet', '@prod', 'cli', 'version' ], "$this->dir/project", null );
		$this->assertSame( [ 'cli', 'version' ], $cli->args );
		$this->assertStringContainsString( 'wiki.example.org', self::ssh( $cli ) );
	}

	public function testTokensAfterDoubleDashDoNotOverrideAlias(): void {
		file_put_contents( "$this->dir/project/mw.ini", "[@prod]\nssh = wiki.example.org\npath = /srv/w\nuser = Bot\n" );
		$cli = new Runner( [ '@prod', 'maint', 'edit', '--', '--user=Admin' ], "$this->dir/project", null );
		$this->assertStringContainsString(
			escapeshellarg( "'mw' '--user=Bot' '--path=/srv/w' 'maint' 'edit' '--' '--user=Admin'" ),
			self::ssh( $cli )
		);
	}

	public function testPathWithoutWikiFails(): void {
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( "No MediaWiki installation found in '$this->dir/project'." );
		( new Runner( [ '--path=project' ], $this->dir, null ) )->wikiPath();
	}

	public function testTildePathIsExpanded(): void {
		mkdir( "$this->dir/project/maintenance" );
		mkdir( "$this->dir/project/includes" );
		touch( "$this->dir/project/maintenance/run.php" );
		file_put_contents( "$this->dir/project/includes/Defines.php", "<?php define( 'MW_VERSION', '1.43.1' );" );
		$cli = new Runner( [ '--path=~/project' ], '/', $this->dir );
		$this->assertSame( realpath( "$this->dir/project" ), $cli->wikiPath() );
	}

	/**
	 * sshCommand() uses -t on a terminal; tests compare against the piped form.
	 */
	private static function ssh( Runner $cli ): string {
		return preg_replace( '/^ssh -t /', 'ssh -T ', (string)$cli->sshCommand() );
	}
}
