<?php

declare(strict_types=1);

namespace MwCli;

use ReflectionClass;
use ReflectionMethod;

/**
 * Parses argv, applies config and aliases, then either builds the SSH command for a remote
 * run or dispatches to a command class.
 */
class Runner
{
    public const VERSION = '0.1.0';
    public const GLOBAL_FLAGS = ['path', 'ssh', 'user', 'quiet'];

    /** @var array<string,class-string<Command>> */
    private array $commands = [];
    /** @var string[] Positional arguments, command and subcommand included. */
    private array $args = [];
    /** @var array<string,string|bool> Command options. */
    private array $assoc = [];
    /** @var array<string,string|bool> Global flags after config and alias were applied. */
    private array $global = [];
    /** @var string[] Config files that were read. */
    private array $configFiles = [];

    private static bool $quiet = false;
    /** @var string[] argv without the alias, for forwarding over SSH. */
    private array $tokens = [];
    private string $cwd;
    private ?string $home;
    private ?string $wikiPath = null;

    /**
     * @param string[] $argv
     */
    public function __construct(array $argv, string $cwd, ?string $home)
    {
        $this->cwd = $cwd;
        $this->home = $home;
        foreach (glob(__DIR__ . '/Command/*.php') ?: [] as $file) {
            $class = __NAMESPACE__ . '\\Command\\' . basename($file, '.php');
            if (is_subclass_of($class, Command::class)) {
                $this->commands[self::kebab(basename($file, '.php'))] = $class;
            }
        }

        // The alias is the first positional token, so global flags may come before it.
        $alias = null;
        foreach ($argv as $i => $token) {
            if (!str_starts_with($token, '--')) {
                if (str_starts_with($token, '@')) {
                    $alias = $token;
                    array_splice($argv, $i, 1);
                }
                break;
            }
        }
        $this->tokens = $argv;
        [$this->args, $assoc] = self::parseArgv($argv);

        $flags = array_intersect_key($assoc, array_flip(self::GLOBAL_FLAGS));
        $this->assoc = array_diff_key($assoc, $flags);

        [$config, $this->configFiles] = Config::load($cwd, $home);
        $this->global = Config::resolve($config, $alias, $flags);
        self::$quiet = !empty($this->global['quiet']);
    }

    /**
     * @return array<string,class-string<Command>> Command classes by command name.
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * @return string[] Config files that were read.
     */
    public function configFiles(): array
    {
        return $this->configFiles;
    }

    /**
     * --key=value, --flag (true), --no-flag (false), everything after -- is positional.
     *
     * @param string[] $argv
     * @return array{0: string[], 1: array<string,string|bool>}
     */
    public static function parseArgv(array $argv): array
    {
        $args = [];
        $assoc = [];
        while ($argv) {
            $token = array_shift($argv);
            if ($token === '--') {
                array_push($args, ...$argv);
                break;
            }
            if (!preg_match('/^--([^=]+)(?:=(.*))?$/s', $token, $match)) {
                $args[] = $token;
                continue;
            }
            $negated = !isset($match[2]) && str_starts_with($match[1], 'no-');
            $assoc[$negated ? substr($match[1], 3) : $match[1]] = $match[2] ?? !$negated;
        }
        return [$args, $assoc];
    }

    /**
     * The ssh command line that runs this invocation on the remote host, or null for a local run.
     *
     * @param bool|null $tty Whether stdin and stdout are terminals; detected when null.
     */
    public function sshCommand(?bool $tty = null): ?string
    {
        $ssh = $this->flag('ssh');
        if ($ssh === null) {
            return null;
        }
        // ponytail: [user@]host[:port][/path] only; add docker:/vagrant: schemes when asked.
        if (!preg_match('#^(?:([^@/]+)@)?([^-:/~@][^:/~@]*)(?::(\d+))?([/~].*)?$#', $ssh, $match)) {
            self::error("Invalid --ssh value '$ssh'. Expected [user@]host[:port][/path].");
        }
        $target = ($match[1] !== '' ? "$match[1]@" : '') . $match[2];
        $path = ($match[4] ?? '') !== '' ? $match[4] : $this->flag('remote-path');

        // Flags given on the command line are forwarded as-is and win over the alias.
        // Tokens after "--" belong to the command (e.g. a maintenance script), not to mw.
        $end = array_search('--', $this->tokens, true);
        $end = is_int($end) ? $end : count($this->tokens);
        $own = array_slice($this->tokens, 0, $end);
        $forward = [
            ...preg_grep('/^--ssh(=|$)/', $own, PREG_GREP_INVERT) ?: [],
            ...array_slice($this->tokens, $end),
        ];
        foreach (['path' => $path, 'user' => $this->flag('remote-user')] as $key => $value) {
            if ($value !== null && !preg_grep("/^--$key(=|$)/", $own)) {
                array_unshift($forward, "--$key=$value");
            }
        }

        $remote = implode(' ', array_map('escapeshellarg', ['mw', ...$forward]));
        // A RemoteCommand in ~/.ssh/config would make ssh refuse the command we pass. This also
        // drops one set on purpose, such as sudo to the web user: mw then runs as the login user.
        // ssh allocates a terminal only when stdin is one too, and then prints "Connection to …
        // closed." at log level INFO. LogLevel=ERROR hides it, along with other INFO-level warnings
        // and pre-login banners; errors such as a changed host key still show.
        $tty ??= stream_isatty(STDIN) && stream_isatty(STDOUT);
        return 'ssh ' . ($tty ? '-t -o LogLevel=ERROR' : '-T') . ' -o RemoteCommand=none'
            . (($match[3] ?? '') !== '' ? " -p $match[3]" : '')
            . ' -- ' . escapeshellarg($target) . ' ' . escapeshellarg($remote);
    }

    public function needsWiki(): bool
    {
        [$class, $method] = $this->resolve($this->args);
        return $class::NEEDS_WIKI && $method !== null && empty($this->assoc['help']);
    }

    /**
     * MediaWiki root from --path, or the nearest parent of the cwd that is one. Looked up once.
     */
    public function wikiPath(): string
    {
        if ($this->wikiPath !== null) {
            return $this->wikiPath;
        }
        $path = $this->flag('path');
        $path = $path === null ? $this->findWikiPath() : $this->expandWikiPath($path);
        $path = realpath($path) ?: $path;

        $defines = (string) file_get_contents("$path/includes/Defines.php");
        preg_match("/define\( 'MW_VERSION', '([^']+)'/", $defines, $match);
        $version = $match[1] ?? null;
        if (version_compare($version ?? '0', '1.43', '<')) {
            self::error('mw needs MediaWiki 1.43 or later, found ' . ($version ?? 'unknown') . '.');
        }
        $this->wikiPath = $path;
        return $path;
    }

    /**
     * The --path value with ~ expanded and made absolute. Fails if it isn't a wiki.
     */
    private function expandWikiPath(string $path): string
    {
        if ($this->home !== null && preg_match('#^~(/|$)#', $path)) {
            $path = $this->home . substr($path, 1);
        }
        $path = str_starts_with($path, '/') ? $path : "$this->cwd/$path";
        if (!self::isWiki($path)) {
            self::error("No MediaWiki installation found in '$path'.");
        }
        return $path;
    }

    /**
     * The nearest parent of the cwd that is a wiki.
     */
    private function findWikiPath(): string
    {
        for ($path = $this->cwd; !self::isWiki($path); $path = dirname($path)) {
            if (dirname($path) === $path) {
                self::error(
                    'This does not seem to be a MediaWiki installation. '
                    . 'Pass --path=<path> or run mw inside one.'
                );
            }
        }
        return $path;
    }

    /**
     * A global flag. Config::resolve() made sure path, ssh, user and their remote- forms
     * are strings.
     */
    public function flag(string $key): ?string
    {
        $value = $this->global[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    private static function isWiki(string $dir): bool
    {
        return is_file("$dir/maintenance/run.php") && is_file("$dir/includes/Defines.php");
    }

    /**
     * Dispatches to the command. Returns the exit code.
     */
    public function run(): int
    {
        try {
            [$command, $method, $args] = $this->resolve($this->args);
            if (!empty($this->assoc['help'])) {
                (new Command\Help($this))->__invoke($this->commandWords(), []);
                return 0;
            }
            if ($method === null) {
                (new Command\Help($this))->usage($this->commandWords());
                return 0;
            }
            $this->validate();
            $instance = new $command($this);
            if ($command::NEEDS_WIKI && $this->flag('user') !== null) {
                // An unknown --user fails every command, not only writes.
                $instance->performer();
            }
            $instance->$method($args, $this->assoc);
            return 0;
        } catch (ExitException $exception) {
            return self::report($exception);
        }
    }

    /**
     * Checks args and options against the method's docblock. bin/mw calls it before loading
     * MediaWiki, so usage errors don't need a wiki.
     */
    public function validate(): void
    {
        [$command, $method, $args] = $this->resolve($this->args);
        if ($method === null || !empty($this->assoc['help'])) {
            return;
        }
        $doc = (new ReflectionMethod($command, $method))->getDocComment() ?: '';
        $problem = Synopsis::validate(Synopsis::parse($doc), $args, $this->assoc);
        if ($problem !== null) {
            self::error($problem . "\nusage: " . $this->usage($command, $method));
        }
    }

    /**
     * The command line after the command and subcommand, as typed, minus mw's global flags.
     * For passing arguments on unchanged, e.g. repeated options.
     *
     * @return string[]
     */
    public function rawArgs(): array
    {
        $skip = count($this->commandWords());
        $out = [];
        foreach ($this->tokens as $i => $token) {
            if ($token === '--') {
                // "--" only ends mw's own flags; what follows is passed on as-is.
                return [...$out, ...array_slice($this->tokens, $i + 1)];
            }
            if (preg_match('/^--(no-)?(' . implode('|', self::GLOBAL_FLAGS) . ')(=|$)/', $token)) {
                continue;
            }
            if ($skip > 0 && !str_starts_with($token, '--')) {
                $skip--;
                continue;
            }
            $out[] = $token;
        }
        return $out;
    }

    /**
     * The command and subcommand words of this invocation, e.g. [ 'page', 'category', 'list' ].
     *
     * @return string[]
     */
    public function commandWords(): array
    {
        [, $method, $args] = $this->resolve($this->args);
        $length = count($this->args) - ($method === null ? 0 : count($args));
        return array_slice($this->args, 0, $length);
    }

    /**
     * Finds the method for the command words. A method named in camelCase is a subcommand of
     * several words (categoryList is `category list`); the longest run of words that names one
     * wins.
     * Words that start subcommand names without naming one (`page category`) are a group:
     * the method is then null and the group's words are returned in place of the args.
     *
     * @param string[] $args
     * @return array{0: class-string<Command>, 1: ?string, 2: string[]}
     *     Class, method (null: show help), args.
     */
    public function resolve(array $args): array
    {
        $name = $args[0] ?? 'help';
        $class = $this->commands[$name] ?? null;
        if ($class === null) {
            self::error(
                "'$name' is not a registered mw command. See 'mw help' for available commands."
                    . self::suggestion($name, array_keys($this->commands))
            );
        }
        if (method_exists($class, '__invoke')) {
            return [$class, '__invoke', array_slice($args, 1)];
        }
        $methods = [];
        foreach (self::subcommands($class) as $method) {
            $methods[self::subcommandName($class, $method)] = $method;
        }
        for ($count = count($args) - 1; $count > 0; $count--) {
            $words = implode(' ', array_slice($args, 1, $count));
            if (isset($methods[$words])) {
                return [$class, $methods[$words], array_slice($args, $count + 1)];
            }
        }
        $group = [];
        foreach (array_slice($args, 1) as $word) {
            $prefix = implode(' ', [...$group, $word]) . ' ';
            $matching = array_filter(
                array_keys($methods),
                static fn (string $subcommand): bool => str_starts_with($subcommand, $prefix)
            );
            if (!$matching) {
                $parent = implode(' ', [$name, ...$group]);
                self::error(
                    "'$word' is not a registered subcommand of '$parent'. "
                        . "See 'mw help $parent' for available subcommands."
                        . self::suggestion($word, array_map(
                            static fn (string $sub): string => explode(' ', $sub)[0],
                            array_keys(self::groupSubcommands($class, $group))
                        ))
                );
            }
            $group[] = $word;
        }
        return [$class, null, $group];
    }

    /**
     * @param class-string<Command> $class
     * @return string[] Public methods that are subcommands.
     */
    public static function subcommands(string $class): array
    {
        $methods = [];
        $public = (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($public as $method) {
            // Constructors and static helpers aren't subcommands.
            if (
                !$method->isStatic()
                && !$method->isConstructor()
                && $method->getDeclaringClass()->getName() === $class
            ) {
                $methods[] = $method->getName();
            }
        }
        return $methods;
    }

    /**
     * @param class-string<Command> $class
     */
    public function usage(string $class, string $method): string
    {
        $name = array_search($class, $this->commands, true);
        $sub = $method === '__invoke' ? '' : ' ' . self::subcommandName($class, $method);
        $doc = (new ReflectionMethod($class, $method))->getDocComment() ?: '';
        $synopsis = Synopsis::parse($doc)['synopsis'];
        return trim("mw $name$sub $synopsis");
    }

    /**
     * The subcommands in a group, sorted by name: name after the group's words => method.
     *
     * @param class-string<Command> $class
     * @param string[] $group
     * @return array<string,string>
     */
    public static function groupSubcommands(string $class, array $group): array
    {
        $prefix = $group ? implode(' ', $group) . ' ' : '';
        $subs = [];
        foreach (self::subcommands($class) as $method) {
            $name = self::subcommandName($class, $method);
            if (str_starts_with($name, $prefix)) {
                $subs[substr($name, strlen($prefix))] = $method;
            }
        }
        ksort($subs);
        return $subs;
    }

    /**
     * A "Did you mean" line for the closest name within two edits (one for short words).
     *
     * @param string[] $names
     */
    private static function suggestion(string $word, array $names): string
    {
        $best = null;
        // Short words need a closer match: `ls` shouldn't suggest `ask`.
        $distance = min(2, max(1, intdiv(strlen($word), 2))) + 1;
        foreach ($names as $name) {
            $edits = levenshtein($word, $name);
            if ($edits < $distance) {
                [$best, $distance] = [$name, $edits];
            }
        }
        return $best === null ? '' : "\nDid you mean '$best'?";
    }

    /**
     * The subcommand's words: categoryList is `category list`, unless the class's NAMES gives
     * the method a hyphenated name.
     *
     * @param class-string<Command> $class
     */
    public static function subcommandName(string $class, string $method): string
    {
        return $class::NAMES[$method] ?? str_replace('-', ' ', self::kebab($method));
    }

    /**
     * SearchReplace or searchReplace to search-replace.
     */
    public static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    public static function report(ExitException $exception): int
    {
        if ($exception->getMessage() !== '') {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
        }
        return $exception->getCode();
    }

    public static function line(string $message): void
    {
        echo $message, "\n";
    }

    /**
     * A progress message; --quiet silences it like success().
     */
    public static function progress(string $message): void
    {
        if (!self::$quiet) {
            echo "$message\n";
        }
    }

    public static function success(string $message): void
    {
        if (!self::$quiet) {
            echo "Success: $message\n";
        }
    }

    public static function warning(string $message): void
    {
        if (!self::$quiet) {
            fwrite(STDERR, "Warning: $message\n");
        }
    }

    public static function error(string $message): never
    {
        throw new ExitException($message, 1);
    }

    /**
     * Asks for a yes/no answer unless --yes was passed. Exits quietly with 0 on anything but "y".
     *
     * @param array<string,string|bool> $assoc
     */
    public static function confirm(string $question, array $assoc): void
    {
        if (!empty($assoc['yes'])) {
            return;
        }
        // On stderr, so the prompt shows even when stdout is redirected.
        fwrite(STDERR, "$question [y/n] ");
        $answer = fgets(STDIN);
        if ($answer === false) {
            self::error('No answer on stdin. Pass --yes to run without confirmation.');
        }
        if (strtolower(trim($answer)) !== 'y') {
            throw new ExitException('', 0);
        }
    }
}
