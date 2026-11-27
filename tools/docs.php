<?php

/**
 * Writes the command reference as Markdown for GitHub Pages, one page per command, group and
 * subcommand, from the same help text `mw help` prints.
 *
 * Usage: php tools/docs.php [<directory>]   (default: build/docs)
 */

declare(strict_types=1);

use MwCli\Command\Help;
use MwCli\Runner;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MwCli\\')) {
        require __DIR__ . '/../src/' . strtr(substr($class, 6), '\\', '/') . '.php';
    }
});

$dir = $argv[1] ?? __DIR__ . '/../build/docs';
$cli = new Runner([], sys_get_temp_dir(), null);

/**
 * The help for these words, as `mw help` prints it, split into its sections.
 *
 * @param string[] $words
 * @return array<string,string> Section title => body without the two-space indent.
 */
$sections = static function (array $words) use ($cli): array {
    $text = (new Help($cli))->page($words);
    $parts = preg_split('/^([A-Z][A-Z ]+)\n\n/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $sections = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        $sections[$parts[$i]] = rtrim((string) preg_replace('/^  /m', '', $parts[$i + 1]));
    }
    return $sections;
};

// Prose may contain tokens like <regex>, which Markdown would read as HTML.
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_NOQUOTES);

// A token at the start of a line with its indented description becomes a definition.
$definitions = static fn (string $body): string => preg_replace_callback(
    '/^(\S.*)\n((?:  .*\n?)+)/m',
    static fn (array $match): string => "`$match[1]`\n: "
        . $escape(trim((string) preg_replace('/\s+/', ' ', $match[2]))) . "\n",
    "$body\n"
) . "\n";

/**
 * Links to the subcommands, relative to the page for these words.
 *
 * @param string[] $words
 */
$subcommands = static function (string $body, array $words) use ($escape): string {
    $list = '';
    foreach (explode("\n", $body) as $line) {
        [$name, $summary] = preg_split('/\s{2,}/', trim($line), 2) ?: ['', ''];
        $base = $words ? [end($words)] : ['commands'];
        $path = implode('/', [...$base, ...explode(' ', $name)]);
        $list .= "- [`$name`]($path.html): " . $escape($summary) . "\n";
    }
    return ($words ? '## Subcommands' : '## Commands') . "\n\n$list\n";
};

/**
 * The page for these words.
 *
 * @param string[] $words
 */
$markdown = static function (array $words) use ($sections, $escape, $definitions, $subcommands): string {
    $help = $sections($words);
    $page = '# ' . ($help['NAME'] ?? 'mw') . "\n\n";
    if ($words) {
        $parent = array_slice($words, 0, -1);
        $up = $parent ? '../' . end($parent) . '.html' : '../index.html';
        $page .= '[' . implode(' ', ['mw', ...$parent]) . "]($up)\n\n";
    }
    $page .= isset($help['DESCRIPTION']) ? $escape($help['DESCRIPTION']) . "\n\n" : '';
    $page .= "## Synopsis\n\n```\n{$help['SYNOPSIS']}\n```\n\n";
    $page .= isset($help['SUBCOMMANDS']) ? $subcommands($help['SUBCOMMANDS'], $words) : '';
    $page .= isset($help['OPTIONS']) ? "## Options\n\n" . $definitions($help['OPTIONS']) : '';
    $examples = preg_replace('/^  /m', '', $help['EXAMPLES'] ?? '');
    $page .= $examples ? "## Examples\n\n```console\n$examples\n```\n\n" : '';
    if (!$words) {
        return $page . "## Global parameters\n\n" . $definitions($help['GLOBAL PARAMETERS']);
    }
    return $page . 'Global parameters work with every command: see [mw]('
        . str_repeat('../', count($words)) . "index.html#global-parameters).\n";
};

// Every page: the top level, each command, each group and each subcommand.
$pages = [[]];
foreach ($cli->commands() as $name => $class) {
    $pages[] = [$name];
    if (method_exists($class, '__invoke')) {
        continue;
    }
    foreach (array_keys(Runner::groupSubcommands($class, [])) as $sub) {
        $subWords = explode(' ', $sub);
        for ($length = 1; $length <= count($subWords); $length++) {
            $pages[] = [$name, ...array_slice($subWords, 0, $length)];
        }
    }
}
$pages = array_unique($pages, SORT_REGULAR);

foreach ($pages as $words) {
    $file = $words ? "$dir/commands/" . implode('/', $words) . '.md' : "$dir/index.md";
    is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
    file_put_contents($file, $markdown($words));
}
file_put_contents("$dir/_config.yml", "title: MW-CLI\ndescription: Command reference\n");
echo 'Wrote ', count($pages), " pages to $dir\n";
