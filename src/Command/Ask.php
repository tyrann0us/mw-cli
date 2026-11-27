<?php

declare(strict_types=1);

namespace MwCli\Command;

use MediaWiki\Context\RequestContext;
use MwCli\Command;
use MwCli\Formatter;
use MwCli\Runner;
use SMW\ProcessingErrorMsgHandler;
use SMW\Query\PrintRequest;
use SMW\Query\QueryProcessor;
use SMW\Services\ServicesFactory;

/**
 * Runs a Semantic MediaWiki query.
 */
class Ask extends Command
{
    /**
     * Runs a Semantic MediaWiki query and lists the pages it finds.
     *
     * The query uses #ask syntax: conditions, then printouts and parameters separated by `|`.
     * Each page is a row with its title and one field per printout. Multiple values are
     * joined with ", ".
     *
     * ## OPTIONS
     *
     * <query>
     * : The query, e.g. '[[Category:Building]]|?Street'.
     *
     * [--limit=<n>]
     * : Maximum number of results, up to Semantic MediaWiki's maximum. Defaults to its default
     * limit. A warning says when there are more.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, ids or count.
     *
     * [--fields=<fields>]
     * : Comma-separated fields to show.
     *
     * [--field=<field>]
     * : Prints only the value of this field.
     *
     * ## EXAMPLES
     *
     *     mw ask '[[Category:Building]]|?Street'
     *     mw ask '[[Street::Main Street]]' --limit=10 --format=ids
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        // SMW\Query\QueryProcessor is new in SMW 7.
        if (
            !$this->services()->getExtensionRegistry()->isLoaded('SemanticMediaWiki')
            || !class_exists(QueryProcessor::class)
        ) {
            Runner::error('mw ask needs Semantic MediaWiki 7 or later.');
        }
        // Split on single | outside [[ ]]. A double || is OR, as in [[A::x||y]] or
        // [[A::x]] || [[B::y]].
        $parts = preg_split('/(?<!\|)\|(?!\|)(?![^\[\]]*\]\])/', $args[0]) ?: [];
        $params = array_map('trim', $parts);
        // Output goes through --format. format=count or format=debug would change the query
        // mode and limit.
        $params = preg_grep('/^format\s*=/i', $params, PREG_GREP_INVERT) ?: [];
        $limit = $this->limit($assocArgs);
        if ($limit !== null) {
            $params[] = "limit=$limit";
        }
        [$query] = QueryProcessor::getQueryAndParamsFromFunctionParams(
            $params,
            SMW_OUTPUT_WIKI,
            QueryProcessor::SPECIAL_PAGE,
            false
        );
        // The store adds errors too, e.g. for unknown properties, and then returns no rows.
        $result = ServicesFactory::getInstance()->getStore()->getQueryResult($query);
        $errors = self::englishErrors($result->getErrors());
        if ($errors) {
            Runner::error(implode(' ', $errors));
        }
        // One field per printout, so the columns are the same with no results.
        $fields = array_map(
            static fn (PrintRequest $printout): string => $printout->isMode(PrintRequest::PRINT_THIS)
                ? 'title'
                : $printout->getLabel(),
            $result->getPrintRequests()
        );
        $rows = [];
        // A row is [] with mainlabel=- and no printouts, so compare with false.
        for ($columns = $result->getNext(); $columns !== false; $columns = $result->getNext()) {
            $row = [];
            foreach ($columns as $i => $column) {
                $values = [];
                $value = $column->getNextDataValue();
                while ($value) {
                    $values[] = $value->getWikiValue();
                    $value = $column->getNextDataValue();
                }
                $row[$fields[$i]] = implode(', ', $values);
            }
            $rows[] = $row;
        }
        Formatter::items($rows, $assocArgs, $fields);
        // The limit may come from --limit, limit= or offset= in the query, or SMW's maximum.
        if ($result->hasFurtherResults()) {
            Runner::warning('There are more results than shown.');
        }
    }

    /**
     * SMW's errors in English, like mw's own. Its decoder only knows the user and content
     * language, so the user language is English for this call only.
     *
     * @param array<mixed> $errors
     * @return string[]
     */
    private static function englishErrors(array $errors): array
    {
        $context = RequestContext::getMain();
        $language = $context->getLanguage();
        $context->setLanguage('en');
        try {
            return ProcessingErrorMsgHandler::normalizeAndDecodeMessages($errors);
        } finally {
            $context->setLanguage($language);
        }
    }
}
