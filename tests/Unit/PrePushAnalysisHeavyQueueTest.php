<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('runs the pre-push PHPStan analysis through the heavy queue at the weight its worker cap calls for', function (): void {
    $finder = new ExecutableFinder;

    if (PHP_OS_FAMILY === 'Windows' || $finder->find('bash') === null || $finder->find('git') === null) {
        test()->markTestSkipped('The pre-push hook fixture requires Unix, Bash and Git.');
    }

    $root = dirname(__DIR__, 2);
    $process = new Process(['bash', $root . '/tests/Shell/pre-push-analysis-heavy.sh'], $root);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
        ->and($process->getOutput())->toContain('PROOF: the pre-push analysis runs through the heavy queue at weight 2, matching the worker cap of phpstan/diff.neon');
});
