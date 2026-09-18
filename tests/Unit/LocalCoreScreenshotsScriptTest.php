<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs no-filter screenshot commands', function (array $arguments, array $expectedCommands): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-core-screenshot-script-' . bin2hex(random_bytes(6));
    $binaryDirectory = $temporary . '/bin';
    $log = $temporary . '/commands.log';

    mkdir($binaryDirectory, 0777, true);

    foreach (['bash', 'npm', 'npx'] as $binary) {
        $path = $binaryDirectory . '/' . $binary;
        file_put_contents($path, <<<'BASH'
#!/bin/sh
printf '%s %s\n' "$(basename "$0")" "$*" >> "$CAPELL_SCREENSHOT_SCRIPT_TEST_LOG"
BASH);
        chmod($path, 0755);
    }

    try {
        $process = new Process(
            ['/bin/bash', 'scripts/local-core-screenshots.sh', ...$arguments],
            $root,
            [
                'PATH' => $binaryDirectory . PATH_SEPARATOR . getenv('PATH'),
                'CAPELL_SCREENSHOT_SCRIPT_TEST_LOG' => $log,
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe($expectedCommands);
    } finally {
        if (is_file($log)) {
            unlink($log);
        }

        foreach (['bash', 'npm', 'npx'] as $binary) {
            unlink($binaryDirectory . '/' . $binary);
        }

        rmdir($binaryDirectory);
        rmdir($temporary);
    }
})->with([
    'validation' => [
        ['--dry-run'],
        [
            'npm ci',
            'npm run screenshots:check',
        ],
    ],
    'capture' => [
        [],
        [
            'npm ci',
            'npm run screenshots -- install-browser',
            'bash scripts/screenshots/prepare-workbench.sh',
            'npm run screenshots',
        ],
    ],
    'capture with installed dependencies' => [
        ['--skip-install'],
        [
            'npm run screenshots -- install-browser',
            'bash scripts/screenshots/prepare-workbench.sh',
            'npm run screenshots',
        ],
    ],
]);

it('refuses to skip the install when no dependency executables are installed', function (): void {
    $root = sys_get_temp_dir() . '/capell-core-screenshot-skip-' . bin2hex(random_bytes(6));

    mkdir($root . '/scripts', 0777, true);
    copy(dirname(__DIR__, 2) . '/scripts/local-core-screenshots.sh', $root . '/scripts/local-core-screenshots.sh');

    try {
        $process = new Process(['/bin/bash', 'scripts/local-core-screenshots.sh', '--skip-install'], $root);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('node_modules/.bin is missing or empty');
    } finally {
        unlink($root . '/scripts/local-core-screenshots.sh');
        rmdir($root . '/scripts');
        rmdir($root);
    }
});
