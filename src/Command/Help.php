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

    /**
     * Shows help for mw and its commands.
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
        if (!$args) {
            Runner::line(
                'usage: mw [@<alias>] [--path=<path>] [--ssh=<host>] [--user=<user>] [--quiet] '
                . "<command>\n"
            );
            Runner::line('Commands:');
            $this->list(array_map(
                static fn (string $class): string => self::summary(new ReflectionClass($class)),
                $this->cli->commands()
            ));
            Runner::line("\nSee 'mw help <command>' for more information on a specific command.");
            return;
        }

        [$class, $method, $group] = $this->cli->resolve($args);
        if ($method === null) {
            // The command, or a group of subcommands such as `page category`.
            $name = implode(' ', [$args[0], ...$group]);
            $prefix = $group ? implode(' ', $group) . ' ' : '';
            if (!$group) {
                Runner::line(self::summary(new ReflectionClass($class)) . "\n");
            }
            Runner::line("usage: mw $name <subcommand>\n");
            Runner::line('Subcommands:');
            $subs = [];
            foreach (Runner::subcommands($class) as $subMethod) {
                $sub = Runner::subcommandName($subMethod);
                if (str_starts_with($sub, $prefix)) {
                    $summary = self::summary(new ReflectionMethod($class, $subMethod));
                    $subs[substr($sub, strlen($prefix))] = $summary;
                }
            }
            $this->list($subs);
            return;
        }

        $doc = (new ReflectionMethod($class, $method))->getDocComment() ?: '';
        $text = Synopsis::parse($doc)['text'];
        [$summary, $body] = array_pad(explode("\n", $text, 2), 2, '');
        Runner::line("NAME\n\n  " . $this->cli->usage($class, $method) . "\n");
        Runner::line("DESCRIPTION\n\n  $summary\n");
        Runner::line(trim($body));
    }

    /**
     * @param array<string,string> $items Name to summary.
     */
    private function list(array $items): void
    {
        $width = max(0, ...array_map('strlen', array_keys($items)));
        foreach ($items as $name => $summary) {
            Runner::line('  ' . str_pad($name, $width + 2) . $summary);
        }
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $reflection
     */
    private static function summary(ReflectionClass|ReflectionMethod $reflection): string
    {
        return Synopsis::parse($reflection->getDocComment() ?: '')['summary'];
    }
}
