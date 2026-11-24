<?php

declare(strict_types=1);

// Prepended to every PHP process in the test container when COVERAGE=1 (see tests/docker/setup.sh).
// Writes raw pcov data per process; tests/coverage/clover.php turns it into a Clover report.

\pcov\start();
register_shutdown_function(static function (): void {
    \pcov\stop();
    $data = array_filter(
        \pcov\collect(\pcov\all),
        static fn (string $file): bool => preg_match('#^/mw-cli/(src/|bin/mw$)#', $file) === 1,
        ARRAY_FILTER_USE_KEY
    );
    if ($data) {
        file_put_contents(
            '/mw-cli/build/coverage/' . uniqid('', true) . '.json',
            json_encode($data)
        );
    }
});
