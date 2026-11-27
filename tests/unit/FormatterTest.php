<?php

declare(strict_types=1);

namespace MwCli\Tests;

use MwCli\ExitException;
use MwCli\Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests run with stdout piped, so the table format is tab-separated.
 */
class FormatterTest extends TestCase
{
    private const ROWS = [
        [ 'id' => 1, 'title' => 'Main Page', 'ns' => 0 ],
        [ 'id' => 2, 'title' => 'Foo, "bar"', 'ns' => 4 ],
    ];

    public static function provideFormats(): array
    {
        return [
            'table' => [ [], "id\ttitle\n1\tMain Page\n2\tFoo, \"bar\"\n" ],
            'json' => [
                [ 'format' => 'json' ],
                '[{"id":1,"title":"Main Page"},{"id":2,"title":"Foo, \"bar\""}]' . "\n",
            ],
            'csv' => [
                [ 'format' => 'csv' ],
                "id,title\n1,\"Main Page\"\n2,\"Foo, \"\"bar\"\"\"\n",
            ],
            'yaml' => [
                [ 'format' => 'yaml' ],
                "---\n-\n  id: 1\n  title: Main Page\n"
                    . "-\n  id: 2\n  title: Foo, \"bar\"\n",
            ],
            'ids' => [ [ 'format' => 'ids' ], "1 2\n" ],
            'count' => [ [ 'format' => 'count' ], "2\n" ],
            'fields' => [
                [ 'fields' => 'title,ns' ],
                "title\tns\nMain Page\t0\nFoo, \"bar\"\t4\n",
            ],
            'field' => [ [ 'field' => 'ns' ], "0\n4\n" ],
            'field as json' => [
                [ 'field' => 'title', 'format' => 'json' ],
                "[\"Main Page\",\"Foo, \\\"bar\\\"\"]\n",
            ],
        ];
    }

    #[DataProvider('provideFormats')]
    public function testItems(array $assoc, string $expected): void
    {
        $this->expectOutputString($expected);
        Formatter::items(self::ROWS, $assoc, ['id', 'title']);
    }

    public function testItemTableIsFieldValuePairs(): void
    {
        $this->expectOutputString("Field\tValue\nid\t1\ntitle\tMain Page\nns\t0\n");
        Formatter::item(self::ROWS[0], []);
    }

    public function testPipedTableEscapesTabsAndNewlines(): void
    {
        $this->expectOutputString("a\na\\tb\\nc\\\\d\n");
        Formatter::items([['a' => "a\tb\nc\\d"]], [], ['a']);
    }

    public function testItemJsonCsvAndYaml(): void
    {
        $this->expectOutputString(
            '{"id":1,"title":"Main Page","ns":0}' . "\n"
                . "Field,Value\nid,1\ntitle,\"Main Page\"\nns,0\n"
                . "---\nid: 1\ntitle: Main Page\nns: 0\n"
                . "\"Main Page\"\n"
        );
        Formatter::item(self::ROWS[0], ['format' => 'json']);
        Formatter::item(self::ROWS[0], ['format' => 'csv']);
        Formatter::item(self::ROWS[0], ['format' => 'yaml']);
        Formatter::item(self::ROWS[0], ['field' => 'title', 'format' => 'json']);
    }

    public function testYamlQuotesOnlyWhereNeeded(): void
    {
        $this->expectOutputString(
            "---\na: \"true\"\nb: \"12\"\nc: \"\"\nd: \"x: y\"\ne: x y\nf: 3\n"
        );
        Formatter::item(
            ['a' => 'true', 'b' => '12', 'c' => '', 'd' => 'x: y', 'e' => 'x y', 'f' => 3],
            ['format' => 'yaml']
        );
    }

    public function testScalarsAreStringified(): void
    {
        $this->expectOutputString("a\tb\tc\ntrue\t\t[1,2]\n");
        Formatter::items([['a' => true, 'b' => null, 'c' => [1, 2]]], [], ['a', 'b', 'c']);
    }

    public function testIdsWithoutFieldsFails(): void
    {
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage('There is no field to print.');
        Formatter::items([[]], ['format' => 'ids'], []);
    }

    public function testInvalidFieldFails(): void
    {
        $this->expectException(ExitException::class);
        $this->expectExceptionMessage('Invalid field: nope.');
        Formatter::items(self::ROWS, ['fields' => 'id,nope'], ['id']);
    }

    public function testInvalidFormatFails(): void
    {
        $this->expectException(ExitException::class);
        Formatter::items(self::ROWS, ['format' => 'xml'], ['id']);
    }
}
