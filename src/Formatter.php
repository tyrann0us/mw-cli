<?php

declare(strict_types=1);

namespace MwCli;

/**
 * Prints rows in the format picked with --format, limited to --fields (or one --field).
 */
class Formatter
{
    public const FORMATS = ['table', 'json', 'csv', 'yaml', 'ids', 'count'];
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * A list of records.
     *
     * @param array<array<string,mixed>> $rows
     * @param array<string,string|bool> $assoc
     * @param string[] $defaultFields Shown unless --fields is passed.
     */
    public static function items(array $rows, array $assoc, array $defaultFields): void
    {
        $fields = self::fields($assoc, $defaultFields, $rows[array_key_first($rows)] ?? null);
        $rows = array_map(static fn (array $row): array => self::pick($row, $fields), array_values($rows));
        if (isset($assoc['field'])) {
            $values = array_column($rows, $fields[0]);
            if (($assoc['format'] ?? '') === 'json') {
                // One JSON array, `[]` for no rows.
                echo json_encode($values, self::JSON), "\n";
                return;
            }
            foreach ($values as $value) {
                echo self::scalar($value), "\n";
            }
            return;
        }

        switch (self::format($assoc)) {
            case 'json':
                echo json_encode($rows, self::JSON), "\n";
                break;
            case 'csv':
                self::csv($fields, $rows);
                break;
            case 'yaml':
                echo self::yaml($rows);
                break;
            case 'ids':
                if (!$fields) {
                    Runner::error('There is no field to print.');
                }
                $ids = array_map([self::class, 'scalar'], array_column($rows, $fields[0]));
                echo implode(' ', $ids), "\n";
                break;
            case 'count':
                echo count($rows), "\n";
                break;
            default:
                echo self::table($fields, $rows);
        }
    }

    /**
     * A single record. The table format prints it as Field/Value rows.
     *
     * @param array<string,mixed> $row
     * @param array<string,string|bool> $assoc
     */
    public static function item(array $row, array $assoc): void
    {
        $fields = self::fields($assoc, array_keys($row), $row);
        $row = self::pick($row, $fields);
        if (isset($assoc['field'])) {
            $value = $row[$fields[0]];
            echo ($assoc['format'] ?? '') === 'json'
                ? json_encode($value, self::JSON)
                : self::scalar($value), "\n";
            return;
        }
        $pairs = [];
        foreach ($row as $field => $value) {
            $pairs[] = ['Field' => $field, 'Value' => $value];
        }
        switch (self::format($assoc, ['table', 'json', 'csv', 'yaml'])) {
            case 'json':
                echo json_encode($row, self::JSON), "\n";
                break;
            case 'csv':
                self::csv(['Field', 'Value'], $pairs);
                break;
            case 'yaml':
                echo self::yaml($row);
                break;
            default:
                echo self::table(['Field', 'Value'], $pairs);
        }
    }

    /**
     * @param string[] $header
     * @param list<array<string,mixed>> $rows
     */
    private static function csv(array $header, array $rows): void
    {
        $out = fopen('php://output', 'w') ?: Runner::error("Can't write to stdout.");
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'scalar'], $row), ',', '"', '');
        }
        fclose($out);
    }

    /**
     * @param array<string,string|bool> $assoc
     * @param string[] $allowed
     */
    private static function format(array $assoc, array $allowed = self::FORMATS): string
    {
        $format = $assoc['format'] ?? 'table';
        if (!is_string($format) || !in_array($format, $allowed, true)) {
            Runner::error('Invalid format. Use one of: ' . implode(', ', $allowed) . '.');
        }
        return $format;
    }

    /**
     * @param array<string,string|bool> $assoc
     * @param string[] $defaults
     * @param array<string,mixed>|null $sample A row to check the requested fields against.
     * @return string[]
     */
    private static function fields(array $assoc, array $defaults, ?array $sample): array
    {
        if (!isset($assoc['field']) && !isset($assoc['fields'])) {
            return $defaults;
        }
        $fields = isset($assoc['field'])
            ? [(string) $assoc['field']]
            : array_map('trim', explode(',', (string) $assoc['fields']));
        $unknown = $sample === null ? [] : array_diff($fields, array_keys($sample));
        if ($unknown) {
            Runner::error('Invalid field: ' . implode(', ', $unknown) . '.');
        }
        return $fields;
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $fields
     * @return array<string,mixed>
     */
    private static function pick(array $row, array $fields): array
    {
        return array_merge(
            array_fill_keys($fields, null),
            array_intersect_key($row, array_flip($fields))
        );
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, self::JSON),
        };
    }

    /**
     * A box table on a terminal, tab-separated values when piped.
     *
     * @param string[] $fields
     * @param array<array<string,mixed>> $rows
     */
    private static function table(array $fields, array $rows): string
    {
        $cells = array_map(
            static fn (array $row): array => array_map([self::class, 'scalar'], array_values($row)),
            $rows
        );
        if (!stream_isatty(STDOUT)) {
            // One record per line: escape what would split it.
            $escape = static fn (string $cell): string => strtr(
                $cell,
                ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']
            );
            $lines = array_map(
                static fn (array $row): string => implode("\t", array_map($escape, $row)),
                [$fields, ...$cells]
            );
            return implode("\n", $lines) . "\n";
        }
        $widths = array_map('mb_strwidth', $fields);
        foreach ($cells as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column], mb_strwidth($cell));
            }
        }
        $dashes = array_map(static fn (int $width): string => str_repeat('-', $width + 2), $widths);
        $rule = '+' . implode('+', $dashes) . "+\n";
        $line = static function (array $row) use ($widths): string {
            $out = '|';
            foreach ($row as $column => $cell) {
                $padding = str_repeat(' ', $widths[$column] - mb_strwidth($cell));
                $out .= ' ' . $cell . $padding . ' |';
            }
            return "$out\n";
        };
        return $rule . $line($fields) . $rule . implode('', array_map($line, $cells)) . $rule;
    }

    /**
     * Block YAML. Strings are plain where YAML reads them unchanged; others
     * are written as JSON scalars.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $data
     */
    private static function yaml(array $data): string
    {
        $out = "---\n";
        $map = static function (array $row, string $indent): string {
            $lines = '';
            foreach ($row as $key => $value) {
                $lines .= "$indent$key: " . self::yamlScalar($value) . "\n";
            }
            return $lines;
        };
        if (!array_is_list($data)) {
            return $out . $map($data, '');
        }
        foreach ($data as $row) {
            $out .= "-\n" . $map($row, '  ');
        }
        return $out;
    }

    private static function yamlScalar(mixed $value): string
    {
        $reserved = ['true', 'false', 'null', 'yes', 'no', 'on', 'off'];
        $plain = is_string($value) && $value !== '' && !is_numeric($value)
            && !preg_match('/^[\s\-?:,\[\]{}#&*!|>\'"%@`~]|: | #|\s$|\n/', $value)
            && !in_array(strtolower($value), $reserved, true);
        return $plain ? $value : (string) json_encode($value, self::JSON);
    }
}
