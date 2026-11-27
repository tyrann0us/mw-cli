<?php

declare(strict_types=1);

namespace MwCli\Command;

use MwCli\Command;
use MwCli\ExitException;
use MwCli\Runner;

/**
 * Runs a MediaWiki maintenance script.
 */
class Maint extends Command
{
    // The script loads MediaWiki itself through maintenance/run.php.
    public const NEEDS_WIKI = false;

    /**
     * Runs a MediaWiki maintenance script through maintenance/run.php.
     *
     * Options after the script name go to the script. Put them after `--` if they clash
     * with mw's own flags (--path, --ssh, --user, --quiet).
     *
     * ## OPTIONS
     *
     * <script>
     * : Script name, e.g. runJobs or update.
     *
     * [<args>...]
     * : Arguments for the script.
     *
     * [--<option>=<value>]
     * : Options for the script.
     *
     * ## EXAMPLES
     *
     *     mw maint runJobs --maxjobs=100
     *     mw maint edit -- --user=Admin Main_Page
     *     mw maint importDump -- --path=dump.xml
     *
     * @param string[] $args
     * @param array<string,string|bool> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        // Raw tokens, so repeated options and their order reach the script unchanged.
        $cmd = array_map('escapeshellarg', [
            PHP_BINARY, $this->cli->wikiPath() . '/maintenance/run.php', ...$this->cli->rawArgs(),
        ]);
        // stderr goes through rewrite(), stdin and stdout stay the terminal's.
        $pipes = [];
        $descriptors = [0 => STDIN, 1 => STDOUT, 2 => ['pipe', 'w']];
        $process = proc_open(implode(' ', $cmd), $descriptors, $pipes)
            ?: Runner::error("Can't run maintenance/run.php.");
        $stderr = $pipes[2];
        while (!feof($stderr)) {
            fwrite(STDERR, self::rewrite((string) fgets($stderr)));
        }
        fclose($stderr);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new ExitException('', $code);
        }
    }

    /**
     * run.php's errors for an unknown script or option, in mw's wording and without the
     * server path. Other lines pass unchanged.
     */
    private static function rewrite(string $line): string
    {
        $patterns = [
            "/^Script '(.+)' not found \(tried path .*\n/"
                => "Error: Unknown maintenance script '$1'.\n",
            '/^ERROR: Unexpected option (--\S+)!\n/' => "Error: Unknown parameter: $1.\n",
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $line) ?? $line;
    }
}
