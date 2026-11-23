<?php

namespace MwCli\Tests;

use MwCli\Synopsis;
use PHPUnit\Framework\TestCase;

class SynopsisTest extends TestCase {
	private const DOC = <<<'DOC'
		/**
		 * Gets a page.
		 *
		 * More text.
		 *
		 * ## OPTIONS
		 *
		 * <title>
		 * : Page title.
		 *
		 * [<more>...]
		 * : More titles.
		 *
		 * --summary=<summary>
		 * : Edit summary.
		 *
		 * [--format=<format>]
		 * : Output format.
		 *
		 * [--yes]
		 * : Skip confirmation.
		 *
		 * [--no-redirect]
		 * : Don't leave a redirect.
		 *
		 * @param string[] $args
		 * --ignored
		 */
		DOC;

	public function testParse(): void {
		$s = Synopsis::parse( self::DOC );
		$this->assertSame( 'Gets a page.', $s['summary'] );
		$this->assertSame( '<title> [<more>...] --summary=<summary> [--format=<format>] [--yes] [--no-redirect]', $s['synopsis'] );
		$this->assertSame( [ 'summary' => true, 'format' => false, 'yes' => false, 'redirect' => false ], $s['assoc'] );
		$this->assertSame( [
			[ 'name' => 'title', 'optional' => false, 'repeating' => false ],
			[ 'name' => 'more', 'optional' => true, 'repeating' => true ],
		], $s['positional'] );
		$this->assertFalse( $s['generic'] );
		$this->assertStringStartsWith( "Gets a page.\n\nMore text.", $s['text'] );
		$this->assertStringEndsWith( ": Don't leave a redirect.", $s['text'] );
	}

	public function testValidate(): void {
		$s = Synopsis::parse( self::DOC );
		$this->assertNull( Synopsis::validate( $s, [ 'A', 'B', 'C' ], [ 'summary' => 'x' ] ) );
		$this->assertSame( 'Missing argument: title.', Synopsis::validate( $s, [], [ 'summary' => 'x' ] ) );
		$this->assertSame( 'Missing --summary.', Synopsis::validate( $s, [ 'A' ], [] ) );
		$this->assertSame( 'Unknown parameter: --nope.', Synopsis::validate( $s, [ 'A' ], [ 'summary' => 'x', 'nope' => true ] ) );
	}

	public function testTooManyArgsAndGenericOptions(): void {
		$s = Synopsis::parse( "/**\n * X.\n *\n * <a>\n *\n * [--<field>=<value>]\n */" );
		$this->assertSame( 'Too many positional arguments: b.', Synopsis::validate( $s, [ 'a', 'b' ], [] ) );
		$this->assertNull( Synopsis::validate( $s, [ 'a' ], [ 'anything' => '1' ] ) );
	}
}
