<?php

namespace MwCli\Tests;

use MwCli\Config;
use MwCli\ExitException;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/mw-cli-test-' . uniqid();
		mkdir( "$this->dir/home/.mw", 0777, true );
		mkdir( "$this->dir/project/sub", 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	public function testProjectConfigOverridesGlobalAndResolvesRelativePath(): void {
		file_put_contents( "$this->dir/home/.mw/config.ini", "user = Global\nquiet = true\n" );
		file_put_contents( "$this->dir/project/mw.ini", "user = Project\npath = w\n" );
		[ $config, $files ] = Config::load( "$this->dir/project/sub", "$this->dir/home" );

		$this->assertSame( [ "$this->dir/home/.mw/config.ini", "$this->dir/project/mw.ini" ], $files );
		$this->assertSame( 'Project', $config['user'] );
		$this->assertTrue( $config['quiet'] );
		$this->assertSame( "$this->dir/project/w", $config['path'] );
	}

	public function testRelativeAliasPathIsRelativeToFileUnlessSsh(): void {
		file_put_contents( "$this->dir/project/mw.ini", "[@a]\npath = w2\n[@b]\nssh = host\npath = w3\n" );
		[ $config ] = Config::load( "$this->dir/project/sub", null );
		$this->assertSame( "$this->dir/project/w2", $config['@a']['path'] );
		$this->assertSame( 'w3', $config['@b']['path'] );
	}

	public function testLocalAliasOverridesConfigAndFlagsOverrideAlias(): void {
		$config = [ 'path' => '/a', 'user' => 'A', '@local' => [ 'path' => '/b', 'user' => 'B' ] ];
		$this->assertSame( [ 'path' => '/b', 'user' => 'C' ], Config::resolve( $config, '@local', [ 'user' => 'C' ] ) );
	}

	public function testSshAliasKeepsPathAndUserForTheRemoteHost(): void {
		$config = [ 'path' => '/local', '@prod' => [ 'ssh' => 'host', 'path' => '/srv', 'user' => 'Bot' ] ];
		$this->assertSame(
			[ 'path' => '/local', 'remote-path' => '/srv', 'remote-user' => 'Bot', 'ssh' => 'host' ],
			Config::resolve( $config, '@prod', [] )
		);
	}

	public function testUnknownAliasFails(): void {
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( "Alias '@nope' not found." );
		Config::resolve( [], '@nope', [] );
	}

	public function testUnknownKeysAreIgnored(): void {
		$this->assertSame( [], Config::resolve( [ 'color' => true ], null, [] ) );
	}
}
