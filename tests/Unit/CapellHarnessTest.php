<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;

it('forwards quality commands and arguments through the harness as the application user', function (string $shortcut, array $command): void {
    $fixture = new CommandFixture;
    $fixture->files->copy('capell');
    $fixture->files->copy('scripts/testing/release-lock-bridge.sh');
    $fixture->files->write('vendor/bin/pest', '#!/usr/bin/env bash');
    chmod($fixture->files->root . '/vendor/bin/pest', 0755);
    $fixture->files->write('bin/python3', "#!/usr/bin/env bash\nshift 2\nexec \"\$@\"\n");
    chmod($fixture->files->root . '/bin/python3', 0755);
    try {
        $process = $fixture->run(['bash', 'capell', $shortcut, '--example=value with spaces'], [
            'CAPELL_SCREENSHOT_RUNNER_HOST_PATH' => '',
            'CAPELL_SCREENSHOT_RUNNER_PATH' => '',
        ]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        $calls = $fixture->calls('docker');
        $call = array_pop($calls);
        throw_unless(is_array($call), RuntimeException::class, 'The harness must dispatch the quality command.');
        $arguments = $call['arguments'];
        expect(array_slice($arguments, -count($command) - 1))->toBe([...$command, '--example=value with spaces'])
            ->and($arguments)->toContain('--user', 'capell');
    } finally {
        $fixture->close();
    }
})->with([
    'formatter' => ['pint', ['vendor/bin/pint']],
    'analysis' => ['analyze', ['composer', 'analyze']],
    'preflight' => ['preflight', ['composer', 'preflight']],
]);
