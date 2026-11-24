<?php

declare(strict_types=1);

namespace MwCli\Command;

use MediaWiki\Parser\ParserOptions;
use MwCli\Command;
use MwCli\Runner;

/**
 * Renders wikitext to HTML.
 */
class Parse extends Command
{
    /**
     * Renders wikitext from a file or stdin to HTML.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : File with the wikitext. Reads stdin if omitted.
     *
     * [--title=<title>]
     * : Page the wikitext is rendered as, for {{PAGENAME}} and relative links. Default: API.
     *
     * ## EXAMPLES
     *
     *     mw parse page.wiki
     *     echo "''Hello''" | mw parse --title=Sandbox
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $titleText = (string) ($assocArgs['title'] ?? 'API');
        $title = $this->services()->getTitleFactory()->newFromText($titleText)
            ?? Runner::error('Invalid title.');
        $file = $args[0] ?? null;
        if ($file !== null && (is_dir($file) || !is_readable($file))) {
            Runner::error("Can't read '$file'.");
        }
        $text = (string) file_get_contents($file ?? 'php://stdin');

        $options = ParserOptions::newFromAnon();
        $output = $this->services()->getParserFactory()->create()->parse($text, $title, $options);
        $output = $output->runOutputPipeline($options, ['enableSectionEditLinks' => false]);
        Runner::line($output->getContentHolderText());
    }
}
