<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('coordinates native Composer and container dispatch with the host release gate', function (): void {
    $root = dirname(__DIR__, 2);
    $environmentPath = getenv('PATH');
    $environmentPath = $environmentPath !== false && $environmentPath !== '' ? $environmentPath : '/usr/bin:/bin';

    $process = new Process(['python3', 'tests/Shell/release-verification-lock.py'], $root, [
        'PATH' => dirname(PHP_BINARY) . ':' . $environmentPath,
    ]);
    $process->setTimeout(30);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
});
