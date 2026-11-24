<?php

declare(strict_types=1);

namespace MwCli\Tests;

use MwCli\Synopsis;
use PHPUnit\Framework\TestCase;

class SynopsisTest extends TestCase
{
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
         * : Don't leave a
         * redirect.
         *
         * @param string[] $args
         * --ignored
         */
        DOC;

    public function testParse(): void
    {
        $synopsis = Synopsis::parse(self::DOC);
        $this->assertSame('Gets a page.', $synopsis['summary']);
        $this->assertSame(
            '<title> [<more>...] --summary=<summary> [--format=<format>] [--yes] [--no-redirect]',
            $synopsis['synopsis']
        );
        $this->assertSame(
            ['summary' => true, 'format' => false, 'yes' => false, 'redirect' => false],
            $synopsis['assoc']
        );
        $this->assertSame([
            [ 'name' => 'title', 'optional' => false, 'repeating' => false ],
            [ 'name' => 'more', 'optional' => true, 'repeating' => true ],
        ], $synopsis['positional']);
        $this->assertFalse($synopsis['generic']);
        $this->assertStringStartsWith("Gets a page.\n\nMore text.", $synopsis['text']);
        $this->assertStringEndsWith(": Don't leave a redirect.", $synopsis['text']);
    }

    public function testValidate(): void
    {
        $synopsis = Synopsis::parse(self::DOC);
        $this->assertNull(Synopsis::validate($synopsis, ['A', 'B', 'C'], ['summary' => 'x']));
        $this->assertSame(
            'Missing argument: title.',
            Synopsis::validate($synopsis, [], ['summary' => 'x'])
        );
        $this->assertSame('Missing --summary.', Synopsis::validate($synopsis, ['A'], []));
        $this->assertSame(
            '--format needs a value.',
            Synopsis::validate($synopsis, ['A'], ['summary' => 'x', 'format' => true])
        );
        $this->assertSame(
            "--yes doesn't take a value.",
            Synopsis::validate($synopsis, ['A'], ['summary' => 'x', 'yes' => 'false'])
        );
        $this->assertSame(
            'Unknown parameter: --nope.',
            Synopsis::validate($synopsis, ['A'], ['summary' => 'x', 'nope' => true])
        );
    }

    public function testTooManyArgsAndGenericOptions(): void
    {
        $synopsis = Synopsis::parse("/**\n * X.\n *\n * <a>\n *\n * [--<field>=<value>]\n */");
        $this->assertSame(
            'Too many positional arguments: b.',
            Synopsis::validate($synopsis, ['a', 'b'], [])
        );
        $this->assertNull(Synopsis::validate($synopsis, ['a'], ['anything' => '1']));
    }
}
