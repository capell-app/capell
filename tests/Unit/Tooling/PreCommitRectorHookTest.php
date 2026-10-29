<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('reports Rector failures without asserting that files need rewriting', function (): void {
    $directory = sys_get_temp_dir() . '/capell-rector-hook-' . bin2hex(random_bytes(6));
    mkdir($directory . '/vendor/bin', 0755, true);
    file_put_contents($directory . '/vendor/bin/rector', "#!/bin/sh\necho 'Cache unavailable' >&2\nexit 1\n");
    chmod($directory . '/vendor/bin/rector', 0755);

    try {
        $process = new Process(['bash', dirname(__DIR__, 3) . '/.pre-commit/rector.sh', 'example.php'], $directory);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput() . $process->getOutput())->toContain('Cache unavailable', 'Rector check failed')
            ->not->toContain('Rector would rewrite staged files.');
    } finally {
        unlink($directory . '/vendor/bin/rector');
        rmdir($directory . '/vendor/bin');
        rmdir($directory . '/vendor');
        rmdir($directory);
    }
});
