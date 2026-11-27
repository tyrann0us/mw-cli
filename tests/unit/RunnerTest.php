<?php

declare(strict_types=1);

namespace MwCli\Tests;

use MwCli\Command\Help;
use MwCli\ExitException;
use MwCli\Runner;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mw-cli-test-' . uniqid();
        mkdir("$this->dir/project/sub", 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testParseArgv(): void
    {
        [$args, $assoc] = Runner::parseArgv(
            ['page', 'get', 'Main Page', '--format=json', '--yes', '--no-color', '--', '--literal']
        );
        $this->assertSame(['page', 'get', 'Main Page', '--literal'], $args);
        $this->assertSame(['format' => 'json', 'yes' => true, 'color' => false], $assoc);
    }

    public function testGlobalFlagsAreSeparatedFromCommandOptions(): void
    {
        $cli = new Runner(['page', 'list', '--user=Admin', '--format=ids'], $this->dir, null);
        $this->assertSame('Admin', $cli->flag('user'));
        // Throws "Unknown parameter: --user" if the global flag reached the command options.
        $cli->validate();
        // Command options stay with the command.
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage('Unknown parameter: --nope.');
        (new Runner(['page', 'list', '--user=Admin', '--nope'], $this->dir, null))->validate();
    }

    public function testCommandsAreDiscoveredFromFileNames(): void
    {
        $cli = new Runner([], $this->dir, null);
        $this->assertSame('MwCli\Command\Help', $cli->commands()['help']);
        $this->assertSame('MwCli\Command\Maint', $cli->commands()['maint']);
    }

    public function testResolveSubcommand(): void
    {
        $cli = new Runner([], $this->dir, null);
        $this->assertSame(
            ['MwCli\Command\Cli', 'version', ['x']],
            $cli->resolve(['cli', 'version', 'x'])
        );
        $this->assertSame(['MwCli\Command\Cli', null, []], $cli->resolve(['cli']));
        $this->assertSame(
            ['MwCli\Command\Help', '__invoke', ['cli']],
            $cli->resolve(['help', 'cli'])
        );
    }

    public function testResolveNestedSubcommand(): void
    {
        $cli = new Runner([], $this->dir, null);
        $this->assertSame(
            ['MwCli\Command\Page', 'categoryList', ['Main Page']],
            $cli->resolve(['page', 'category', 'list', 'Main Page'])
        );
        // The longest run of words that names a subcommand wins; the rest are args.
        $this->assertSame(
            ['MwCli\Command\Page', 'list', ['category']],
            $cli->resolve(['page', 'list', 'category'])
        );
        $this->assertSame(
            ['MwCli\Command\Page', null, ['category']],
            $cli->resolve(['page', 'category'])
        );
        $this->assertSame(['MwCli\Command\Page', null, []], $cli->resolve(['page']));
        $this->assertSame('category list', Runner::subcommandName('categoryList'));
        $this->assertSame(
            'mw page category list <title> [--limit=<n>] [--format=<format>] '
                . '[--fields=<fields>] [--field=<field>]',
            $cli->usage('MwCli\Command\Page', 'categoryList')
        );
    }

    public function testCommandWordsSkipArgs(): void
    {
        $words = static fn (array $argv) => (new Runner($argv, sys_get_temp_dir(), null))->commandWords();
        $this->assertSame(
            ['page', 'category', 'list'],
            $words(['page', 'category', 'list', 'Foo'])
        );
        $this->assertSame(['page', 'category'], $words(['page', 'category']));
        $this->assertSame(['maint'], $words(['maint', 'edit']));
        $cli = new Runner(['page', 'category', 'list', 'Foo', '--format=json'], $this->dir, null);
        $this->assertSame(['Foo', '--format=json'], $cli->rawArgs());
    }

    public function testUnknownNestedSubcommandFails(): void
    {
        $cli = new Runner([], $this->dir, null);
        foreach (
            [
            "'nope' is not a registered subcommand of 'page category'. "
                . "See 'mw help page category' for available subcommands."
                => [ 'page', 'category', 'nope' ],
            "'category-list' is not a registered subcommand of 'page'. "
                . "See 'mw help page' for available subcommands." => [ 'page', 'category-list' ],
            "'categoryList' is not a registered subcommand of 'page'. "
                . "See 'mw help page' for available subcommands." => [ 'page', 'categoryList' ],
            ] as $message => $args
        ) {
            try {
                $cli->resolve($args);
                $this->fail(implode(' ', $args) . ' was accepted');
            } catch (ExitException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testHelpForNestedSubcommands(): void
    {
        $cli = new Runner([], $this->dir, null);
        ob_start();
        (new Help($cli))->__invoke(['page', 'category', 'list'], []);
        (new Help($cli))->__invoke(['page', 'category'], []);
        (new Help($cli))->__invoke(['page'], []);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString("NAME\n\n  mw page category list\n", $out);
        $this->assertStringContainsString("SYNOPSIS\n\n  mw page category list <title>", $out);
        $this->assertStringContainsString("    \$ mw page category list Main_Page\n", $out);
        $this->assertStringContainsString(
            "NAME\n\n  mw page category\n\nDESCRIPTION\n\n  Lists the categories of a page.\n\n"
                . "SYNOPSIS\n\n  mw page category <command>\n\n"
                . "SUBCOMMANDS\n\n  list  Lists the categories of a page.\n\nGLOBAL PARAMETERS",
            $out
        );
        $this->assertMatchesRegularExpression(
            '/^  category list +Lists the categories of a page\.$/m',
            $out
        );
    }

    public function testValidateSkipsHelpAndRejectsBadArgs(): void
    {
        (new Runner(['cli', 'version', '--nope', '--help'], $this->dir, null))->validate();
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage("Unknown parameter: --nope.\nusage: mw cli version");
        (new Runner(['cli', 'version', '--nope'], $this->dir, null))->validate();
    }

    public function testQuietSilencesSuccessAndYesSkipsConfirm(): void
    {
        $this->expectOutputString("Success: shown\n");
        new Runner(['cli', 'version'], $this->dir, null);
        Runner::success('shown');
        new Runner(['--quiet', 'cli', 'version'], $this->dir, null);
        Runner::success('hidden');
        Runner::warning('hidden');
        Runner::confirm('Sure?', ['yes' => true]);
    }

    public function testUnknownCommandFails(): void
    {
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage("'nope' is not a registered mw command");
        (new Runner([], $this->dir, null))->resolve(['nope']);
    }

    public function testRawArgsKeepRepeatedOptionsAndDropGlobalFlags(): void
    {
        $argv = [
            '--quiet',
            'maint',
            'x',
            '--skip=a',
            '--user=Bot',
            '--no-quiet',
            '--skip=b',
            '--',
            '--user=Admin',
        ];
        $cli = new Runner($argv, $this->dir, null);
        $this->assertSame(['x', '--skip=a', '--skip=b', '--user=Admin'], $cli->rawArgs());
    }

    public function testNoSshMeansLocalRun(): void
    {
        $this->assertNull((new Runner(['cli', 'version'], $this->dir, null))->sshCommand());
    }

    public function testSshCommandForwardsArgsAndPath(): void
    {
        $argv = ['page', 'get', "It's", '--ssh=deploy@wiki.example.org:2222/var/www/w'];
        $cli = new Runner($argv, $this->dir, null);
        $this->assertSame(
            "ssh -T -o RemoteCommand=none -p 2222 -- 'deploy@wiki.example.org' "
                . escapeshellarg("'mw' '--path=/var/www/w' 'page' 'get' 'It'\\''s'"),
            (string) $cli->sshCommand(false)
        );
    }

    public function testSshAliasAppliesRemotePathAndUser(): void
    {
        file_put_contents(
            "$this->dir/project/mw.ini",
            "path = w\n[@prod]\nssh = wiki.example.org\npath = /srv/w\nuser = Bot\n"
        );
        $cli = new Runner(['@prod', 'cli', 'version'], "$this->dir/project/sub", null);
        $this->assertSame(
            "ssh -T -o RemoteCommand=none -- 'wiki.example.org' "
                . escapeshellarg("'mw' '--user=Bot' '--path=/srv/w' 'cli' 'version'"),
            (string) $cli->sshCommand(false)
        );
    }

    public function testCommandLineFlagsWinOverAlias(): void
    {
        file_put_contents(
            "$this->dir/project/mw.ini",
            "[@prod]\nssh = wiki.example.org\npath = /srv/w\n"
        );
        $cli = new Runner(['@prod', '--path=/other', 'cli', 'version'], "$this->dir/project", null);
        $this->assertStringContainsString(
            escapeshellarg("'mw' '--path=/other' 'cli' 'version'"),
            (string) $cli->sshCommand(false)
        );
    }

    public function testSshOnATerminalHidesTheClosedNotice(): void
    {
        $cli = new Runner(['cli', 'version', '--ssh=wiki.example.org:2222/w'], $this->dir, null);
        $this->assertSame(
            "ssh -t -o LogLevel=ERROR -o RemoteCommand=none -p 2222 -- 'wiki.example.org' "
                . escapeshellarg("'mw' '--path=/w' 'cli' 'version'"),
            $cli->sshCommand(true)
        );
    }

    public function testSshHostCannotStartWithDash(): void
    {
        $this->expectException(ExitException::class);
        (new Runner(['cli', 'version', '--ssh=-oProxyCommand=x'], $this->dir, null))->sshCommand();
    }

    public function testWikiPathIsFoundByWalkingUp(): void
    {
        $wiki = "$this->dir/project";
        mkdir("$wiki/maintenance");
        mkdir("$wiki/includes");
        touch("$wiki/maintenance/run.php");
        file_put_contents("$wiki/includes/Defines.php", "<?php define( 'MW_VERSION', '1.43.1' );");
        $this->assertSame(realpath($wiki), (new Runner([], "$wiki/sub", null))->wikiPath());

        file_put_contents("$wiki/includes/Defines.php", "<?php define( 'MW_VERSION', '1.42.3' );");
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage('needs MediaWiki 1.43 or later, found 1.42.3');
        (new Runner([], "$wiki/sub", null))->wikiPath();
    }

    public function testFlagsWithoutValueFail(): void
    {
        foreach (['--user', '--ssh', '--path'] as $flag) {
            try {
                new Runner(['cli', 'info', $flag], $this->dir, null);
                $this->fail("$flag without value was accepted");
            } catch (ExitException $exception) {
                $this->assertSame("$flag needs a value.", $exception->getMessage());
            }
        }
    }

    public function testAliasMayFollowGlobalFlags(): void
    {
        file_put_contents("$this->dir/project/mw.ini", "[@prod]\nssh = wiki.example.org\n");
        $cli = new Runner(['--quiet', '@prod', 'cli', 'version'], "$this->dir/project", null);
        $this->assertSame(['cli', 'version'], $cli->commandWords());
        $this->assertStringContainsString('wiki.example.org', (string) $cli->sshCommand(false));
    }

    public function testTokensAfterDoubleDashDoNotOverrideAlias(): void
    {
        file_put_contents(
            "$this->dir/project/mw.ini",
            "[@prod]\nssh = wiki.example.org\npath = /srv/w\nuser = Bot\n"
        );
        $cli = new Runner(
            ['@prod', 'maint', 'edit', '--', '--user=Admin'],
            "$this->dir/project",
            null
        );
        $this->assertStringContainsString(
            escapeshellarg("'mw' '--user=Bot' '--path=/srv/w' 'maint' 'edit' '--' '--user=Admin'"),
            (string) $cli->sshCommand(false)
        );
    }

    public function testPathWithoutWikiFails(): void
    {
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage("No MediaWiki installation found in '$this->dir/project'.");
        (new Runner(['--path=project'], $this->dir, null))->wikiPath();
    }

    public function testTildePathIsExpanded(): void
    {
        mkdir("$this->dir/project/maintenance");
        mkdir("$this->dir/project/includes");
        touch("$this->dir/project/maintenance/run.php");
        file_put_contents(
            "$this->dir/project/includes/Defines.php",
            "<?php define( 'MW_VERSION', '1.43.1' );"
        );
        $cli = new Runner(['--path=~/project'], '/', $this->dir);
        $this->assertSame(realpath("$this->dir/project"), $cli->wikiPath());
    }
}
