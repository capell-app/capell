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
    // Hang ceiling only: a busy host must slow this down, never fail it.
    $process->setTimeout(300);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
        ->and($process->getOutput())->toContain('PASS new manifest source resolves inside worktree')
        ->and($process->getOutput())->toContain('PASS top-level vendor metadata provisioned')
        ->and($process->getOutput())->toContain('PASS third-party packages are real trees isolated from the primary')
        ->and($process->getOutput())->toContain('PASS linked sibling packages resolve from the worktree')
        ->and($process->getOutput())->toContain('PASS non-clone fallback re-points linked sibling packages')
        ->and($process->getOutput())->toContain('PASS primary autoload metadata unchanged');
});

it('re-points a worktree npm link that npm ci left dangling, and never touches the primary', function (): void {
    $finder = new ExecutableFinder;

    if (PHP_OS_FAMILY === 'Windows' || $finder->find('bash') === null || $finder->find('git') === null) {
        test()->markTestSkipped('Re-pointing worktree npm links requires Unix, Bash and Git.');
    }

    $root = dirname(__DIR__, 2);
    $process = new Process(['bash', $root . '/tests/Shell/repoint-linked-siblings.sh'], $root);
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
        ->and($process->getOutput())->toContain('PASS dangling worktree link re-pointed at the primary sibling, and its .bin shim resolves')
        ->and($process->getOutput())->toContain('PASS healthy and unknown links are left alone')
        ->and($process->getOutput())->toContain('PASS primary checkout is never modified')
        ->and($process->getOutput())->toContain('PASS outside a git repository it exits cleanly');
});
