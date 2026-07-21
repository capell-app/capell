<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs commands with leading environment assignments and preserves their exit code', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY,
        'scripts/with-lock.php',
        'with-lock-script-test',
        '--',
        'CAPELL_LOCK_TEST=available',
        '/bin/sh',
        '-c',
        '[ "$CAPELL_LOCK_TEST" != available ]',
    ], $root, ['CAPELL_NO_LOCK' => '1']);

    $process->run();

    expect($process->getExitCode())->toBe(1);
});
