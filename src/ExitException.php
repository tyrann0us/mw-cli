<?php

declare(strict_types=1);

namespace MwCli;

/**
 * Ends the command. The runner prints the message (if any) to stderr and exits with the code.
 */
class ExitException extends \RuntimeException
{
}
