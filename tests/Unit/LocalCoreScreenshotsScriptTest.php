<?php

declare(strict_types=1);

use Capell\Tests\Support\ScriptFixture;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('prepares a Core-only screenshot install without a theme', function (): void {
    $fixture = new ScriptFixture;
    foreach (['scripts/screenshots/prepare-workbench.sh', 'scripts/screenshots/testbench-skeleton.sh', 'scripts/screenshots/laravel-v13.10.1-package.json', 'workbench/php/php.ini'] as $path) {
        $fixture->copy($path);
    }

    $fixture->write('capture.php', <<<'CAPTURE'
<?php
if (($argv[1] ?? '') === 'vendor/bin/testbench' && ($argv[2] ?? '') === 'capell:install') {
    $options = [];
    foreach (array_slice($argv, 3) as $argument) {
        [$key, $value] = array_pad(explode('=', $argument, 2), 2, true);
        $options[$key] = $value;
    }
    file_put_contents(__DIR__ . '/install.json', json_encode([
        'options' => $options,
        'database' => getenv('DB_DATABASE'),
        'connection' => getenv('DB_CONNECTION'),
    ], JSON_THROW_ON_ERROR));
}
CAPTURE);
    $fixture->write('bin/php', <<<'BINARY'
#!/bin/sh
exec "$FIXTURE_PHP" "$FIXTURE_CAPTURE" "$@"
BINARY);
    $fixture->write('bin/node', "#!/bin/sh\nexit 0\n");
    chmod($fixture->root . '/bin/php', 0755);
    chmod($fixture->root . '/bin/node', 0755);
    $fixture->write('workbench/database/migrations/0001_create_users_table.php', 'owned migration');
    try {
        $process = new Process(['/bin/bash', 'scripts/screenshots/prepare-workbench.sh'], $fixture->root, [
            'PATH' => $fixture->root . '/bin' . PATH_SEPARATOR . getenv('PATH'),
            'FIXTURE_PHP' => PHP_BINARY,
            'FIXTURE_CAPTURE' => $fixture->root . '/capture.php',
        ]);
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);

        expect($process->run())->toBe(0, $process->getErrorOutput());
        $install = json_decode(file_get_contents($fixture->root . '/install.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($install['options']['--package-mode'])->toBe('core')
            ->and($install['options']['--theme'])->toBe('none')
            ->and($install['connection'])->toBe('sqlite')
            ->and($install['database'])->toBe($fixture->root . '/workbench/database/screenshots.sqlite')
            ->and(file_get_contents($fixture->root . '/workbench/database/migrations/0001_create_users_table.php'))->toBe('owned migration');
    } finally {
        $fixture->close();
    }
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
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);
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
            'bash scripts/screenshots/testbench-skeleton.sh restore',
        ],
    ],
    'capture with installed dependencies' => [
        ['--skip-install'],
        [
            'bash scripts/screenshots/prepare-workbench.sh',
            'npm run screenshots',
            'bash scripts/screenshots/testbench-skeleton.sh restore',
        ],
    ],
    'capture keeping the prepared workbench' => [
        ['--skip-install', '--keep-workbench'],
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
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);
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

    // The install needs an application manifest whose build script runs without node_modules; formatting of its shipped JSON is irrelevant.
    $applicationManifest = json_decode((string) file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
    expect($applicationManifest)->toEqual([
        '$schema' => 'https://www.schemastore.org/package.json',
        'private' => true,
        'type' => 'module',
        'scripts' => ['build' => 'echo "Screenshot workbench: theme CSS is compiled by scripts/screenshots, so there is no Vite build to run."', 'dev' => 'vite'],
        'devDependencies' => [
            '@tailwindcss/vite' => '^4.0.0',
            'concurrently' => '^10.0.3',
            'laravel-vite-plugin' => '^3.1',
            'tailwindcss' => '^4.0.0',
            'vite' => '^8.0.0',
        ],
        'optionalDependencies' => ['@laravel/multiplex' => '^0.4.1'],
    ]);

    mkdir($binaryDirectory, 0777, true);
    mkdir($root . '/scripts/screenshots', 0777, true);
    mkdir($runtime, 0777, true);
    copy($repository . '/scripts/screenshots/prepare-workbench.sh', $root . '/scripts/screenshots/prepare-workbench.sh');
    copy($repository . '/scripts/screenshots/testbench-skeleton.sh', $root . '/scripts/screenshots/testbench-skeleton.sh');
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
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);
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
    copy($repository . '/scripts/screenshots/testbench-skeleton.sh', $root . '/scripts/screenshots/testbench-skeleton.sh');
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
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);
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

it('links the checkout frontend package into vendor for the workbench Vite config', function (string $existing, bool $expectLink): void {
    $fixture = new ScriptFixture;
    foreach (['scripts/screenshots/prepare-workbench.sh', 'scripts/screenshots/testbench-skeleton.sh', 'scripts/screenshots/laravel-v13.10.1-package.json', 'workbench/php/php.ini'] as $path) {
        $fixture->copy($path);
    }

    $fixture->write('packages/frontend/resources/js/capell-vite-inputs.js', 'export const capellViteInputs = () => [];');
    $fixture->write('bin/php', "#!/bin/sh\nexit 0\n");
    $fixture->write('bin/node', "#!/bin/sh\nexit 0\n");
    chmod($fixture->root . '/bin/php', 0755);
    chmod($fixture->root . '/bin/node', 0755);

    if ($existing === 'directory') {
        $fixture->write('vendor/capell-app/frontend/installed.txt', 'installed');
    }

    try {
        $process = new Process(['/bin/bash', 'scripts/screenshots/prepare-workbench.sh'], $fixture->root, [
            'PATH' => $fixture->root . '/bin' . PATH_SEPARATOR . getenv('PATH'),
        ]);
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);

        expect($process->run())->toBe(0, $process->getErrorOutput());

        $link = $fixture->root . '/vendor/capell-app/frontend';
        if ($expectLink) {
            expect(is_link($link))->toBeTrue()
                ->and(readlink($link))->toBe('../../packages/frontend')
                ->and(is_file($link . '/resources/js/capell-vite-inputs.js'))->toBeTrue();
        } else {
            expect(is_link($link))->toBeFalse()
                ->and(file_get_contents($link . '/installed.txt'))->toBe('installed');
        }
    } finally {
        $fixture->close();
    }
})->with([
    'fresh worktree' => ['none', true],
    'real installed package' => ['directory', false],
]);
it('ships a workbench build script that succeeds where no frontend dependencies are installed', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/scripts/screenshots/laravel-v13.10.1-package.json'), true, flags: JSON_THROW_ON_ERROR);
    $empty = sys_get_temp_dir() . '/capell-core-screenshot-build-' . bin2hex(random_bytes(6));
    mkdir($empty);

    try {
        // The skeleton has neither node_modules nor vendor/capell-app, so a real Vite build cannot run there.
        $process = Process::fromShellCommandline($manifest['scripts']['build'], $empty, ['PATH' => '/usr/bin:/bin']);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    } finally {
        (new Filesystem)->deleteDirectory($empty);
    }
});

it('restores the shared Testbench skeleton exactly after preparation rewrote it', function (): void {
    $repository = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-core-screenshot-skeleton-' . bin2hex(random_bytes(6));
    $root = $temporary . '/root';
    $binaryDirectory = $temporary . '/bin';
    $skeleton = $root . '/vendor/orchestra/testbench-core/laravel';

    mkdir($binaryDirectory, 0777, true);
    mkdir($root . '/scripts/screenshots', 0777, true);
    mkdir($skeleton . '/database/migrations', 0777, true);
    mkdir($skeleton . '/app', 0777, true);
    file_put_contents($skeleton . '/app/Kept.php', 'original');
    file_put_contents($skeleton . '/database/migrations/0001_create_users_table.php', 'original');
    foreach (['prepare-workbench.sh', 'testbench-skeleton.sh', 'laravel-v13.10.1-package.json'] as $file) {
        copy($repository . '/scripts/screenshots/' . $file, $root . '/scripts/screenshots/' . $file);
    }

    // The install is stubbed, but it still rewrites the skeleton the way the real one does.
    file_put_contents($binaryDirectory . '/php', <<<'BASH'
#!/bin/sh
if [ "${2:-}" = "capell:install" ]; then
    echo rewritten > vendor/orchestra/testbench-core/laravel/app/Kept.php
    echo generated > vendor/orchestra/testbench-core/laravel/app/Generated.php
fi
BASH);
    foreach (['node', 'npm'] as $binary) {
        file_put_contents($binaryDirectory . '/' . $binary, "#!/bin/sh\nexit 0\n");
    }

    foreach (['php', 'node', 'npm'] as $binary) {
        chmod($binaryDirectory . '/' . $binary, 0755);
    }

    $fingerprint = static function () use ($skeleton): array {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skeleton, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($skeleton))] = md5_file($file->getPathname());
        }

        ksort($files);

        return $files;
    };
    $before = $fingerprint();

    try {
        $environment = ['PATH' => $binaryDirectory . PATH_SEPARATOR . getenv('PATH')];
        $prepare = new Process(['/bin/bash', 'scripts/screenshots/prepare-workbench.sh'], $root, $environment);
        $prepare->setTimeout(300);
        $prepare->run();

        expect($prepare->getExitCode())->toBe(0, $prepare->getErrorOutput())
            ->and(file_get_contents($skeleton . '/app/Kept.php'))->toBe("rewritten\n")
            ->and(is_file($skeleton . '/.capell-workbench-prepared'))->toBeTrue();

        // A second preparation before any restore must keep the first, pristine copy.
        $again = new Process(['/bin/bash', 'scripts/screenshots/prepare-workbench.sh'], $root, $environment);
        $again->setTimeout(300);
        $again->run();

        $restore = new Process(['/bin/bash', 'scripts/screenshots/testbench-skeleton.sh', 'restore'], $root, $environment);
        $restore->setTimeout(300);
        $restore->run();

        expect($again->getExitCode())->toBe(0, $again->getErrorOutput())
            ->and($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
            ->and($fingerprint())->toBe($before)
            ->and(is_file($skeleton . '/.capell-workbench-prepared'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
});
