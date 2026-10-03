<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('prepares the Core workbench without mutating the shared Testbench vendor tree', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2) . '/scripts/screenshots/prepare-workbench.sh');

    expect($script)->toBeString()
        ->toContain('--package-mode=core')
        ->toContain('--theme=none');
});

it('runs no-filter screenshot commands', function (array $arguments, array $expectedCommands): void {
    $temporary = sys_get_temp_dir() . '/capell-core-screenshot-script-' . bin2hex(random_bytes(6));
    $binaryDirectory = $temporary . '/bin';
    $log = $temporary . '/commands.log';

    // Run against a throwaway root rather than the repository. --skip-install
    // genuinely requires a populated node_modules/.bin, so running here made the
    // result depend on whether the host had installed dependencies: green on a
    // developer machine, red on CI, and evidence of nothing either way.
    $root = $temporary . '/root';

    mkdir($binaryDirectory, 0777, true);
    mkdir($root . '/scripts', 0777, true);
    mkdir($root . '/node_modules/.bin', 0777, true);
    copy(dirname(__DIR__, 2) . '/scripts/local-core-screenshots.sh', $root . '/scripts/local-core-screenshots.sh');
    touch($root . '/node_modules/.bin/placeholder');

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
        unlink($root . '/node_modules/.bin/placeholder');
        rmdir($root . '/node_modules/.bin');
        rmdir($root . '/node_modules');
        unlink($root . '/scripts/local-core-screenshots.sh');
        rmdir($root . '/scripts');
        rmdir($root);
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
            'npx playwright install chromium',
            'bash scripts/screenshots/prepare-workbench.sh',
            'npm run screenshots',
        ],
    ],
    'capture with installed dependencies' => [
        ['--skip-install'],
        [
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

it('prepares a real Laravel manifest before installation without replacing an existing manifest', function (?string $existingManifest, int $expectedExit): void {
    $repository = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-core-screenshot-manifest-' . bin2hex(random_bytes(6));
    $root = $temporary . '/root';
    $binaryDirectory = $temporary . '/bin';
    $runtime = $root . '/vendor/orchestra/testbench-core/laravel';
    $fixture = $repository . '/scripts/screenshots/laravel-v13.10.1-package.json';
    $expectedManifest = $temporary . '/expected-package.json';
    $log = $temporary . '/commands.log';

    expect(hash_file('sha256', $fixture))->toBe('023d511d768e05b69e8127e18ae0e3d4a2e91702e8dd82917c655e53e5908de5');

    mkdir($binaryDirectory, 0777, true);
    mkdir($root . '/scripts/screenshots', 0777, true);
    mkdir($runtime, 0777, true);
    copy($repository . '/scripts/screenshots/prepare-workbench.sh', $root . '/scripts/screenshots/prepare-workbench.sh');
    copy($fixture, $root . '/scripts/screenshots/laravel-v13.10.1-package.json');
    file_put_contents($expectedManifest, $existingManifest ?? file_get_contents($fixture));
    if ($existingManifest !== null) {
        file_put_contents($runtime . '/package.json', $existingManifest);
    }

    file_put_contents($temporary . '/check-manifest.php', <<<'PHP'
<?php
declare(strict_types=1);

$path = getcwd() . '/vendor/orchestra/testbench-core/laravel/package.json';
if (! is_file($path)) {
    throw new RuntimeException('Generated Laravel package.json was absent before capell:install.');
}
json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
if (file_get_contents($path) !== file_get_contents(getenv('CAPELL_SCREENSHOT_TEST_EXPECTED_MANIFEST'))) {
    throw new RuntimeException('The application manifest was replaced or did not match Laravel.');
}
file_put_contents(getenv('CAPELL_SCREENSHOT_SCRIPT_TEST_LOG'), "manifest validated before capell:install\n", FILE_APPEND);
PHP);

    file_put_contents($binaryDirectory . '/php', <<<'BASH'
#!/bin/sh
set -e
if [ "${2:-}" = "capell:install" ]; then
    "$CAPELL_SCREENSHOT_TEST_PHP" "$CAPELL_SCREENSHOT_TEST_CHECKER"
fi
BASH);
    file_put_contents($binaryDirectory . '/node', "#!/bin/sh\nexit 0\n");
    chmod($binaryDirectory . '/php', 0755);
    chmod($binaryDirectory . '/node', 0755);

    try {
        $process = new Process(
            ['/bin/bash', 'scripts/screenshots/prepare-workbench.sh'],
            $root,
            [
                'PATH' => $binaryDirectory . PATH_SEPARATOR . getenv('PATH'),
                'CAPELL_SCREENSHOT_TEST_PHP' => PHP_BINARY,
                'CAPELL_SCREENSHOT_TEST_CHECKER' => $temporary . '/check-manifest.php',
                'CAPELL_SCREENSHOT_TEST_EXPECTED_MANIFEST' => $expectedManifest,
                'CAPELL_SCREENSHOT_SCRIPT_TEST_LOG' => $log,
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe($expectedExit, $process->getErrorOutput())
            ->and(file_get_contents($runtime . '/package.json'))->toBe(file_get_contents($expectedManifest));
        if ($expectedExit === 0) {
            expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['manifest validated before capell:install']);
        } else {
            expect($process->getErrorOutput())->toContain('Syntax error');
        }
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
})->with([
    'missing manifest' => [null, 0],
    'existing application manifest' => ['{"private":true,"scripts":{"build":"custom build"},"dependencies":{"axios":"^1.0"}}', 0],
    'malformed application manifest' => ['{"private":', 255],
]);

it('preserves the host PHP configuration for preparation children', function (?string $inheritedScanDirectory): void {
    $repository = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-core-screenshot-ini-' . bin2hex(random_bytes(6));
    $root = $temporary . '/root';
    $binaryDirectory = $temporary . '/bin';
    mkdir($binaryDirectory, 0777, true);
    mkdir($root . '/scripts/screenshots', 0777, true);
    mkdir($root . '/workbench/php', 0777, true);
    mkdir($temporary . '/inherited', 0777, true);
    file_put_contents($temporary . '/inherited/custom.ini', "precision=11\n");
    copy($repository . '/scripts/screenshots/prepare-workbench.sh', $root . '/scripts/screenshots/prepare-workbench.sh');
    copy($repository . '/scripts/screenshots/laravel-v13.10.1-package.json', $root . '/scripts/screenshots/laravel-v13.10.1-package.json');
    copy($repository . '/workbench/php/php.ini', $root . '/workbench/php/php.ini');
    $scanDirectory = $inheritedScanDirectory === 'custom' ? $temporary . '/inherited' : $inheritedScanDirectory;
    $log = $temporary . '/environment.json';

    file_put_contents($temporary . '/check-environment.php', <<<'PHP_WRAP'
    <?php
    file_put_contents(getenv('CAPELL_SCREENSHOT_SCRIPT_TEST_LOG'), json_encode([
        'phprc' => getenv('PHPRC'),
        'scan' => getenv('PHP_INI_SCAN_DIR'),
        'loaded' => php_ini_loaded_file(),
        'memory' => ini_get('memory_limit'),
        'precision' => ini_get('precision'),
    ], JSON_THROW_ON_ERROR));
    PHP_WRAP);
    file_put_contents($binaryDirectory . '/php', <<<'BASH'
#!/bin/sh
set -e
if [ "${1:-}" = "scripts/configure-testbench-runtime-role.php" ]; then
    "$CAPELL_SCREENSHOT_TEST_PHP" "$CAPELL_SCREENSHOT_TEST_CHECKER"
fi
BASH);
    file_put_contents($binaryDirectory . '/node', "#!/bin/sh\nexit 0\n");
    chmod($binaryDirectory . '/php', 0755);
    chmod($binaryDirectory . '/node', 0755);

    try {
        $baseline = new Process([PHP_BINARY, '-r', 'echo php_ini_loaded_file();'], env: ['PHPRC' => false, 'PHP_INI_SCAN_DIR' => false]);
        $baseline->mustRun();
        $process = new Process(['/bin/bash', 'scripts/screenshots/prepare-workbench.sh'], $root, [
            'PATH' => $binaryDirectory . PATH_SEPARATOR . getenv('PATH'),
            'PHPRC' => $temporary . '/stale-php.ini',
            'PHP_INI_SCAN_DIR' => $scanDirectory ?? false,
            'CAPELL_SCREENSHOT_TEST_PHP' => PHP_BINARY,
            'CAPELL_SCREENSHOT_TEST_CHECKER' => $temporary . '/check-environment.php',
            'CAPELL_SCREENSHOT_SCRIPT_TEST_LOG' => $log,
        ]);
        $process->run();
        $environment = json_decode(file_get_contents($log), true, flags: JSON_THROW_ON_ERROR);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($environment['phprc'])->toBeFalse()
            ->and($environment['scan'])->toBe(($scanDirectory ?: '') . ':' . realpath($root) . '/workbench/php')
            ->and($environment['loaded'])->toBe($baseline->getOutput())
            ->and($environment['memory'])->toBe('-1');
        if ($scanDirectory) {
            expect($environment['precision'])->toBe('11');
        }
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
})->with(['default scan' => [null], 'empty scan' => [''], 'inherited scan' => ['custom']]);
