<?php

declare(strict_types=1);

namespace MwCli;

/**
 * Reads ~/.mw/config.ini and the nearest mw.ini, and merges them with an alias and CLI flags.
 *
 * INI instead of YAML because config is read before MediaWiki (and its YAML parser) loads.
 */
class Config
{
    /**
     * @return array{0: array<string,mixed>, 1: string[]} Merged config and the files that were
     *     read.
     */
    public static function load(string $cwd, ?string $home): array
    {
        $files = self::files($cwd, $home);
        $config = [];
        foreach ($files as $file) {
            $config = array_replace_recursive($config, self::read($file));
        }
        return [$config, $files];
    }

    /**
     * @return string[] ~/.mw/config.ini and the nearest mw.ini, if they exist.
     */
    private static function files(string $cwd, ?string $home): array
    {
        $files = [];
        if ($home !== null && is_file("$home/.mw/config.ini")) {
            $files[] = "$home/.mw/config.ini";
        }
        for ($dir = $cwd;; $dir = dirname($dir)) {
            if (is_file("$dir/mw.ini")) {
                $files[] = "$dir/mw.ini";
                break;
            }
            if (dirname($dir) === $dir) {
                break;
            }
        }
        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private static function read(string $file): array
    {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Runner::error() reports it.
        $ini = @parse_ini_file($file, true, INI_SCANNER_TYPED);
        if ($ini === false) {
            Runner::error("Can't parse config file $file.");
        }
        // Remember where each path was set: a relative one is relative to that file, unless
        // it ends up in an SSH alias, which only resolve() knows after all files are merged.
        if (isset($ini['path'])) {
            $ini['path-base'] = dirname($file);
        }
        foreach (array_keys(array_filter($ini, 'is_array')) as $section) {
            if (isset($ini[$section]['path'])) {
                $ini[$section]['path-base'] = dirname($file);
            }
        }
        return $ini;
    }

    /**
     * Applies config, then the alias section, then CLI flags. An alias with "ssh" points to
     * another host, so its path and user become remote-path and remote-user for that host.
     *
     * @param array<string,mixed> $config
     * @param string|null $alias
     * @param array<string,string|bool> $flags
     * @return array<string,string|bool>
     */
    public static function resolve(array $config, ?string $alias, array $flags): array
    {
        $global = array_intersect_key(
            self::rebase(array_filter($config, static fn (mixed $value): bool => !is_array($value))),
            array_flip(Runner::GLOBAL_FLAGS)
        );
        if ($alias !== null) {
            $global = array_replace($global, self::alias($config, $alias));
        }

        $out = [];
        foreach (array_replace($global, $flags) as $key => $value) {
            $out[$key] = self::normalize($key, $value);
        }
        return $out;
    }

    /**
     * The alias section's global flags. With "ssh", path and user become remote-path and
     * remote-user.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function alias(array $config, string $alias): array
    {
        $section = $config[$alias] ?? null;
        if (!is_array($section)) {
            Runner::error("Alias '$alias' not found.");
        }
        if (!isset($section['ssh'])) {
            return array_intersect_key(self::rebase($section), array_flip(Runner::GLOBAL_FLAGS));
        }
        $section = array_intersect_key($section, array_flip(Runner::GLOBAL_FLAGS));
        $remote = [];
        foreach (['path', 'user'] as $key) {
            if (isset($section[$key])) {
                $remote["remote-$key"] = $section[$key];
                unset($section[$key]);
            }
        }
        return $remote + $section;
    }

    /**
     * quiet as a bool, everything else as a non-empty string.
     */
    private static function normalize(string $key, mixed $value): string|bool
    {
        if ($key === 'quiet') {
            return is_string($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? Runner::error('--quiet must be true or false.')
                : (bool) $value;
        }
        if (is_bool($value) || $value === '' || !is_scalar($value)) {
            Runner::error("--$key needs a value.");
        }
        return (string) $value;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function rebase(array $values): array
    {
        $path = $values['path'] ?? null;
        if (is_string($path) && isset($values['path-base']) && !preg_match('#^[/~]#', $path)) {
            $values['path'] = $values['path-base'] . '/' . $path;
        }
        return $values;
    }
}
