<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('runs the staged-file PHPStan hook in the heavy queue light lane', function (): void {
    $finder = new ExecutableFinder;

    if (PHP_OS_FAMILY === 'Windows' || $finder->find('bash') === null || $finder->find('git') === null) {
        test()->markTestSkipped('The pre-commit hook fixture requires Unix, Bash and Git.');
    }

    $root = dirname(__DIR__, 2);
    $process = new Process(['bash', $root . '/tests/Shell/pre-commit-analysis-light-lane.sh'], $root);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
        ->and($process->getOutput())->toContain('PROOF: the staged-file PHPStan hook enters the heavy light lane');
});

it('scopes the light-lane static lock to its checkout and keeps other static work host-wide', function (): void {
    $finder = new ExecutableFinder;

    if (PHP_OS_FAMILY === 'Windows' || $finder->find('bash') === null || $finder->find('perl') === null) {
        test()->markTestSkipped('The lock-scope fixture requires Unix, Bash and Perl.');
    }

    $root = dirname(__DIR__, 2);
    $process = new Process(
        ['bash', $root . '/tests/Shell/with-lock-light-lane-scope.sh'],
        $root,
        ['PATH' => dirname(PHP_BINARY) . PATH_SEPARATOR . (getenv('PATH') ?: '')],
    );
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
        ->and($process->getOutput())->toContain('PROOF: light-lane static locks are per checkout');
});
