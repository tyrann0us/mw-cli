<?php

declare(strict_types=1);

namespace MwCli\Command;

use MwCli\Command;
use MwCli\Runner;
use MwCli\Synopsis;
use ReflectionClass;
use ReflectionMethod;

/**
 * Shows help for mw and its commands.
 */
class Help extends Command
{
    public const NEEDS_WIKI = false;

    private const GLOBAL_PARAMETERS = <<<'TXT'
        GLOBAL PARAMETERS

          @<alias>
              Run with the settings of an alias from mw.ini or ~/.mw/config.ini.

          --path=<path>
              Path to the MediaWiki installation.

          --ssh=[<user>@]<host>[:<port>][<path>]
              Run the command on a remote host over SSH.

          --user=<user>
              Name or ID of the user that runs the command and makes its edits.

          --quiet
              Suppress success and warning messages.

        TXT;

    /**
     * Shows help for mw and its commands.
     *
     * On a terminal, the help opens in $PAGER (default: less -R).
     *
     * ## OPTIONS
     *
     * [<command>...]
     * : Command and subcommand to show help for.
     *
     * ## EXAMPLES
     *
     *     mw help page get
     *     mw help page category list
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $text = $this->page($args);
        if (!stream_isatty(STDOUT)) {
            echo $text;
            return;
        }
        $pager = getenv('PAGER') ?: 'less -R';
        $pipe = popen($pager, 'w') ?: Runner::error("Can't run the pager '$pager'.");
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A pager that quits early.
        @fwrite($pipe, $text);
        if (pclose($pipe) === 127) {
            // The shell didn't find the pager: print the help instead.
            echo $text;
        }
    }

    /**
     * What `mw <group>` prints without a subcommand: a usage line per subcommand.
     *
     * @param string[] $words The command and group words, e.g. ['page', 'category'].
     */
    public function usage(array $words): void
    {
        [$class, , $group] = $this->cli->resolve($words);
        $lines = [];
        foreach (Runner::groupSubcommands($class, $group) as $method) {
            $lines[] = $this->cli->usage($class, $method);
        }
        $name = implode(' ', $words);
        echo 'usage: ', implode("\n   or: ", $lines), "\n\n",
            "See 'mw help $name <command>' for more information on a specific command.\n";
    }

    /**
     * The whole help text for these words, as `mw help` prints it. tools/docs.php builds the
     * command reference from it.
     *
     * @param string[] $words
     */
    public function page(array $words): string
    {
        return $this->text($words) . self::GLOBAL_PARAMETERS;
    }

    /**
     * The help without GLOBAL PARAMETERS.
     *
     * @param string[] $args
     */
    private function text(array $args): string
    {
        if (!$args) {
            $commands = array_map(
                static fn (string $class): string => self::summary(new ReflectionClass($class)),
                $this->cli->commands()
            );
            ksort($commands);
            return self::section('NAME', 'mw')
                . self::section('DESCRIPTION', 'Manage MediaWiki through the command line.')
                . self::section('SYNOPSIS', 'mw <command>')
                . self::section('SUBCOMMANDS', self::list($commands));
        }

        // The third value is the group's words without a method, the leftover args with one.
        [$class, $method, $rest] = $this->cli->resolve($args);
        $group = $method === null ? $rest : [];
        if ($method === null) {
            // The command, or a group of subcommands such as `page category`.
            $name = implode(' ', ['mw', $args[0], ...$group]);
            $subs = array_map(
                static fn (string $method): string => self::summary(new ReflectionMethod($class, $method)),
                Runner::groupSubcommands($class, $group)
            );
            // A group of words has no docblock of its own: its description is in GROUPS.
            $description = $group
                ? $class::GROUPS[implode(' ', $group)] ?? ''
                : self::summary(new ReflectionClass($class));
            $description = $description === '' ? '' : self::section('DESCRIPTION', $description);
            return self::section('NAME', $name)
                . $description
                . self::section('SYNOPSIS', "$name <command>")
                . self::section('SUBCOMMANDS', self::list($subs));
        }

        $doc = (new ReflectionMethod($class, $method))->getDocComment() ?: '';
        $text = Synopsis::parse($doc)['text'];
        [$description, $sections] = array_pad(explode("\n## ", $text, 2), 2, '');
        $usage = $this->cli->usage($class, $method);
        $words = array_slice($args, 0, count($args) - count($rest));
        return self::section('NAME', 'mw ' . implode(' ', $words))
            . self::section('DESCRIPTION', self::indent($description))
            . self::section('SYNOPSIS', $usage)
            . ($sections === '' ? '' : self::indent("## $sections") . "\n\n");
    }

    /**
     * Help layout for the docblock text: "## X" headings become "X", option tokens are
     * indented by two spaces and their ": description" by four, examples get a "$ " prompt.
     */
    private static function indent(string $text): string
    {
        $lines = [];
        $examples = false;
        foreach (explode("\n", trim($text)) as $line) {
            if (str_starts_with($line, '## ')) {
                $line = substr($line, 3);
                $examples = $line === 'EXAMPLES';
            } elseif (str_starts_with($line, ': ')) {
                $line = '    ' . substr($line, 2);
            } elseif (str_starts_with($line, '    ')) {
                $line = $examples ? '    $ ' . ltrim($line) : $line;
            } elseif ($line !== '') {
                $line = "  $line";
            }
            $lines[] = $line;
        }
        return ltrim(implode("\n", $lines));
    }

    private static function section(string $title, string $body): string
    {
        return "$title\n\n  $body\n\n";
    }

    /**
     * @param array<string,string> $items Name to summary.
     */
    private static function list(array $items): string
    {
        $width = max(0, ...array_map('strlen', array_keys($items)));
        $lines = [];
        foreach ($items as $name => $summary) {
            $lines[] = str_pad($name, $width + 2) . $summary;
        }
        return implode("\n  ", $lines);
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $reflection
     */
    private static function summary(ReflectionClass|ReflectionMethod $reflection): string
    {
        return Synopsis::parse($reflection->getDocComment() ?: '')['summary'];
    }
}
