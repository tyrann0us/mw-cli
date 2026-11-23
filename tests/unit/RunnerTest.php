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

	public function testUnknownCommandFails(): void {
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( "'nope' is not a registered mw command" );
		( new Runner( [], $this->dir, null ) )->resolve( [ 'nope' ] );
	}

	public function testNoSshMeansLocalRun(): void {
		$this->assertNull( ( new Runner( [ 'cli', 'version' ], $this->dir, null ) )->sshCommand() );
	}

	public function testSshCommandForwardsArgsAndPath(): void {
		$cli = new Runner( [ 'page', 'get', "It's", '--ssh=deploy@wiki.example.org:2222/var/www/w' ], $this->dir, null );
		$this->assertSame(
			"ssh -T -p 2222 -- 'deploy@wiki.example.org' "
				. escapeshellarg( "'mw' '--path=/var/www/w' 'page' 'get' 'It'\\''s'" ),
			$cli->sshCommand()
		);
	}

	public function testSshAliasAppliesRemotePathAndUser(): void {
		file_put_contents( "$this->dir/project/mw.ini", "path = w\n[@prod]\nssh = wiki.example.org\npath = /srv/w\nuser = Bot\n" );
		$cli = new Runner( [ '@prod', 'cli', 'version' ], "$this->dir/project/sub", null );
		$this->assertSame(
			"ssh -T -- 'wiki.example.org' " . escapeshellarg( "'mw' '--user=Bot' '--path=/srv/w' 'cli' 'version'" ),
			$cli->sshCommand()
		);
	}

	public function testCommandLineFlagsWinOverAlias(): void {
		file_put_contents( "$this->dir/project/mw.ini", "[@prod]\nssh = wiki.example.org\npath = /srv/w\n" );
		$cli = new Runner( [ '@prod', '--path=/other', 'cli', 'version' ], "$this->dir/project", null );
		$this->assertStringContainsString( escapeshellarg( "'mw' '--path=/other' 'cli' 'version'" ), $cli->sshCommand() );
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
		$this->expectExceptionMessage( 'needs MediaWiki 1.43 or later, found 1.42.3' );
		( new Runner( [], "$wiki/sub", null ) )->wikiPath();
	}
}
