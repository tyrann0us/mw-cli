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
		file_put_contents( "$this->dir/home/.mw/config.ini", "ssh = global\npath = /global\n" );
		file_put_contents( "$this->dir/project/mw.ini", "path = w\n" );
		[ $config, $files ] = Config::load( "$this->dir/project/sub", "$this->dir/home" );

		$this->assertSame( [ "$this->dir/home/.mw/config.ini", "$this->dir/project/mw.ini" ], $files );
		$this->assertSame( 'global', $config['ssh'] );
		$this->assertSame( "$this->dir/project/w", Config::resolve( $config, null, [] )['path'] );
	}

	public function testRelativeAliasPathIsRelativeToFileUnlessSsh(): void {
		file_put_contents( "$this->dir/project/mw.ini", "[@a]\npath = w2\n[@b]\nssh = host\npath = w3\n" );
		[ $config ] = Config::load( "$this->dir/project/sub", null );
		$this->assertSame( "$this->dir/project/w2", Config::resolve( $config, '@a', [] )['path'] );
		$this->assertSame( 'w3', Config::resolve( $config, '@b', [] )['remote-path'] );
	}

	public function testSshFromGlobalConfigKeepsProjectPathRemote(): void {
		file_put_contents( "$this->dir/home/.mw/config.ini", "[@prod]\nssh = host\n" );
		file_put_contents( "$this->dir/project/mw.ini", "[@prod]\npath = w\n" );
		[ $config ] = Config::load( "$this->dir/project", "$this->dir/home" );
		$this->assertSame( 'w', Config::resolve( $config, '@prod', [] )['remote-path'] );
	}

	public function testLocalAliasOverridesConfigAndFlagsOverrideAlias(): void {
		$config = [ 'path' => '/a', '@local' => [ 'path' => '/b' ] ];
		$this->assertSame( [ 'path' => '/b' ], Config::resolve( $config, '@local', [] ) );
		$this->assertSame( [ 'path' => '/c' ], Config::resolve( $config, '@local', [ 'path' => '/c' ] ) );
	}

	public function testSshAliasKeepsPathAndUserForTheRemoteHost(): void {
		$config = [ 'path' => '/local', '@prod' => [ 'ssh' => 'host', 'path' => '/srv', 'user' => 'Bot' ] ];
		$this->assertSame(
			[ 'path' => '/local', 'remote-path' => '/srv', 'ssh' => 'host' ],
			Config::resolve( $config, '@prod', [] )
		);
	}

	public function testUnparsableFileFails(): void {
		file_put_contents( "$this->dir/project/mw.ini", "path = \"unterminated\n" );
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( "Can't parse config file $this->dir/project/mw.ini." );
		Config::load( "$this->dir/project", null );
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
