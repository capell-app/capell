<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('provisions new source mappings without mutating the primary autoloader', function (): void {
    $finder = new ExecutableFinder;

    if (PHP_OS_FAMILY === 'Windows' || $finder->find('bash') === null || $finder->find('composer') === null || $finder->find('python3') === null) {
        test()->markTestSkipped('Host-only worktree provisioning requires Unix, Bash, Composer and Python.');
    }

    $root = dirname(__DIR__, 2);
    $process = new Process(['bash', $root . '/tests/Shell/init-worktree.sh'], $root);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
        ->and($process->getOutput())->toContain('PASS new manifest source resolves inside worktree')
        ->and($process->getOutput())->toContain('PASS primary autoload metadata unchanged');
});
