<?php

declare(strict_types=1);

use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
use Capell\Marketplace\Support\MarketplaceComposerAuthWorkspace;
use Capell\Marketplace\Support\MarketplaceComposerEnvironment;
use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);
    config()->set('capell.process.composer.no_cache', false);
    config()->set('capell.process.composer.cache_dir');
    config()->set('capell.process.composer.memory_limit', '-1');
});

/**
 * @return array<string, string|false>
 */
function expectedMarketplaceComposerRunnerEnvironmentForTest(string $composerHome, string $home): array
{
    return [
        'COMPOSER_CACHE_DIR' => storage_path('framework/composer/cache'),
        'COMPOSER_HOME' => $composerHome,
        'COMPOSER_MEMORY_LIMIT' => '-1',
        'COMPOSER_AUTH' => false,
        'COMPOSER_TOKEN' => false,
        'GIT_ASKPASS' => false,
        'GIT_TERMINAL_PROMPT' => '0',
        'GITHUB_TOKEN' => false,
        'GITHUB_AUTH_TOKEN' => false,
        'GITLAB_TOKEN' => false,
        'HOME' => $home,
        'PACKAGIST_TOKEN' => false,
        'SSH_AUTH_SOCK' => false,
    ];
}

it('keeps the composer cache by default so an install does not re-download the world', function (): void {
    $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'composerRequireArguments');

    expect($method->invoke(new ProcessMarketplaceComposerRunner, 'vendor/example', '^1.2'))->toBe([
        'require',
        '--no-interaction',
        '--no-scripts',
        '--no-audit',
        '--no-progress',
        '--prefer-dist',
        '--with-all-dependencies',
        'vendor/example:^1.2',
    ]);
});

it('disables the composer cache only when the host asks for it', function (): void {
    config()->set('capell.process.composer.no_cache', true);

    $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'composerRequireArguments');
    $arguments = $method->invoke(new ProcessMarketplaceComposerRunner, 'vendor/example', '^1.2');

    // --no-cache is a global option, so Composer only accepts it before the command.
    expect($arguments[0])->toBe('--no-cache')
        ->and($arguments[1])->toBe('require');
});

it('fails clearly when the composer home directory cannot be created', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_composer_home_file_');

    try {
        expect(function () use ($path): void {
            new MarketplaceComposerAuthWorkspace()->ensureDirectory($path);
        })->toThrow(RuntimeException::class, 'Unable to create Composer home directory: ' . $path);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('gives composer an unlimited memory limit and a cache directory it owns', function (): void {
    $environment = new MarketplaceComposerEnvironment()->variables('/tmp/capell-composer-home');

    expect($environment)->toMatchArray([
        'COMPOSER_MEMORY_LIMIT' => '-1',
        'COMPOSER_CACHE_DIR' => storage_path('framework/composer/cache'),
    ]);
});

it('uses the configured composer cache directory when one is given', function (): void {
    config()->set('capell.process.composer.cache_dir', '/var/cache/capell-composer');

    $environment = new MarketplaceComposerEnvironment()->variables('/tmp/capell-composer-home');

    expect($environment['COMPOSER_CACHE_DIR'])->toBe('/var/cache/capell-composer');
});

it('passes the host proxy configuration through to composer', function (): void {
    $previousProxies = [];

    foreach (['HTTP_PROXY' => 'http://proxy.test:3128', 'HTTPS_PROXY' => 'http://proxy.test:3129', 'NO_PROXY' => 'localhost'] as $key => $value) {
        $previousProxies[$key] = getenv($key);
        putenv($key . '=' . $value);
    }

    try {
        $environment = new MarketplaceComposerEnvironment()->variables('/tmp/capell-composer-home');

        expect($environment)->toMatchArray([
            'HTTP_PROXY' => 'http://proxy.test:3128',
            'HTTPS_PROXY' => 'http://proxy.test:3129',
            'NO_PROXY' => 'localhost',
        ]);
    } finally {
        foreach ($previousProxies as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});

it('blocks composer before touching an immutable release root', function (): void {
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): mixed => (new ProcessMarketplaceComposerRunner)->require('vendor/example', '^1.2', 5))
        ->toThrow(
            RuntimeException::class,
            'Installing a Marketplace extension with Composer is blocked because CAPELL_RELEASE_ROOT_MODE is immutable',
        );
});

it('blocks composer when server-side tooling is disabled', function (): void {
    config()->set('capell.server_side_tooling', false);

    expect(fn (): mixed => (new ProcessMarketplaceComposerRunner)->require('vendor/example', '^1.2', 5))
        ->toThrow(
            RuntimeException::class,
            'Installing a Marketplace extension with Composer is blocked because CAPELL_SERVER_SIDE_TOOLING is disabled',
        );
});

it('runs composer through the resolved php binary with an isolated composer home', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-runner-' . bin2hex(random_bytes(4));

    mkdir($binDirectory, 0755, true);

    $phpPath = $binDirectory . '/php';
    $composerPath = $binDirectory . '/composer';

    file_put_contents($phpPath, "#!/bin/sh\nexec \"$@\"\n");
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
echo "COMPOSER_HOME=$COMPOSER_HOME"
echo "HOME=$HOME"
printf '%s\n' "$@"
echo "composer stderr" >&2
exit 7
SH);
    chmod($phpPath, 0755);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    $previousHome = getenv('HOME');
    putenv('PATH=' . $binDirectory);
    putenv('HOME');

    try {
        $result = (new ProcessMarketplaceComposerRunner)->require('vendor/example', '^1.2', 5);
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
        @unlink($phpPath);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }

    expect($result->exitCode)->toBe(7)
        ->and($result->output)->toContain('COMPOSER_HOME=' . storage_path('framework/composer'))
        ->and($result->output)->toContain('HOME=' . storage_path('framework/composer'))
        ->and($result->output)->not->toContain('--no-cache')
        ->and($result->output)->toContain('--no-scripts')
        ->and($result->output)->toContain('vendor/example:^1.2')
        ->and($result->errorOutput)->toContain('composer stderr')
        ->and(is_dir(storage_path('framework/composer')))->toBeTrue();
});

it('writes marketplace composer auth to an isolated composer home and redacts output', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-runner-' . bin2hex(random_bytes(4));

    mkdir($binDirectory, 0755, true);

    $phpPath = $binDirectory . '/php';
    $composerPath = $binDirectory . '/composer';

    file_put_contents($phpPath, "#!/bin/sh\nexec \"$@\"\n");
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
echo "COMPOSER_HOME=$COMPOSER_HOME"
test -f "$COMPOSER_HOME/auth.json" && echo "AUTH_FILE_PRESENT"
echo "token=ghp_secret_token"
exit 7
SH);
    chmod($phpPath, 0755);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    $existingAuthHomes = glob(storage_path('framework/composer/marketplace-auth-*')) ?: [];
    putenv('PATH=' . $binDirectory);

    try {
        $result = (new ProcessMarketplaceComposerRunner)->requireWithComposerAuth(
            composerName: 'vendor/example',
            versionConstraint: '^1.2',
            timeoutSeconds: 5,
            composerAuth: [
                'github-oauth' => [
                    'github.com' => 'ghp_secret_token',
                ],
            ],
        );
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        @unlink($phpPath);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }

    expect($result->exitCode)->toBe(7)
        ->and($result->output)->toContain('COMPOSER_HOME=' . storage_path('framework/composer/marketplace-auth-'))
        ->and($result->output)->toContain('AUTH_FILE_PRESENT')
        ->and($result->output)->not->toContain('ghp_secret_token')
        ->and($result->output)->toContain('[redacted]')
        ->and(array_values(array_diff(glob(storage_path('framework/composer/marketplace-auth-*')) ?: [], $existingAuthHomes)))->toBe([]);
});

it('keeps the existing home directory available for git configuration', function (): void {
    $previousHome = getenv('HOME');
    putenv('HOME=/Users/example');

    try {
        expect(new MarketplaceComposerEnvironment()->variables('/tmp/capell-composer-home'))
            ->toBe(expectedMarketplaceComposerRunnerEnvironmentForTest('/tmp/capell-composer-home', '/Users/example'));
    } finally {
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
    }
});

it('falls back to composer home when no home directory is available', function (): void {
    $previousHome = getenv('HOME');
    putenv('HOME');

    try {
        expect(new MarketplaceComposerEnvironment()->variables('/tmp/capell-composer-home'))
            ->toBe(expectedMarketplaceComposerRunnerEnvironmentForTest('/tmp/capell-composer-home', '/tmp/capell-composer-home'));
    } finally {
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
    }
});

it('removes ambient marketplace composer credentials from the child process environment', function (): void {
    $environmentKeys = [
        'COMPOSER_AUTH',
        'COMPOSER_TOKEN',
        'GIT_ASKPASS',
        'GITHUB_TOKEN',
        'GITHUB_AUTH_TOKEN',
        'GITLAB_TOKEN',
        'PACKAGIST_TOKEN',
        'SSH_AUTH_SOCK',
    ];
    $previousEnvironment = [];

    foreach ($environmentKeys as $key) {
        $previousEnvironment[$key] = getenv($key);
        putenv($key . '=ambient-secret');
    }

    try {
        $environment = new MarketplaceComposerEnvironment()->variables('/tmp/capell-composer-home');

        expect($environment)->toMatchArray([
            'COMPOSER_AUTH' => false,
            'COMPOSER_TOKEN' => false,
            'GIT_ASKPASS' => false,
            'GIT_TERMINAL_PROMPT' => '0',
            'GITHUB_TOKEN' => false,
            'GITHUB_AUTH_TOKEN' => false,
            'GITLAB_TOKEN' => false,
            'PACKAGIST_TOKEN' => false,
            'SSH_AUTH_SOCK' => false,
        ]);
    } finally {
        foreach ($previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});

it('sweeps composer auth directories abandoned by installs that never finished', function (): void {
    $workspace = new MarketplaceComposerAuthWorkspace;
    $workspace->ensureDirectory($workspace->root());

    $abandoned = $workspace->create();
    $inFlight = $workspace->create();

    file_put_contents($abandoned . '/auth.json', '{}');
    touch($abandoned, Date::now()->getTimestamp() - MarketplaceComposerAuthWorkspace::staleAfterSeconds() - 60);

    try {
        expect($workspace->stale())->toBe([$abandoned])
            ->and($workspace->sweep())->toBe(1)
            ->and(is_dir($abandoned))->toBeFalse()
            // An install still running owns its directory, so age is what
            // separates debris from a workspace in use.
            ->and(is_dir($inFlight))->toBeTrue();
    } finally {
        $workspace->removeDirectory($inFlight);
    }
});

it('never treats a directory younger than the configured job timeout as stale', function (): void {
    // The directory mtime is set when the install starts and never refreshed,
    // so a cutoff shorter than the run would delete a live auth.json mid-download.
    config()->set('capell.process.composer.timeout_seconds', 7200);

    $workspace = new MarketplaceComposerAuthWorkspace;
    $workspace->ensureDirectory($workspace->root());

    $longRunning = $workspace->create();
    touch($longRunning, Date::now()->subMinutes(90)->getTimestamp());

    try {
        expect(MarketplaceComposerAuthWorkspace::staleAfterSeconds())->toBeGreaterThan(7200)
            ->and($workspace->stale())->not->toContain($longRunning);
    } finally {
        $workspace->removeDirectory($longRunning);
    }
});

it('reports abandoned composer auth directories without failing the operations doctor', function (): void {
    $workspace = new MarketplaceComposerAuthWorkspace;
    $workspace->ensureDirectory($workspace->root());

    $statusWithoutDebris = BuildMarketplaceOperationsDoctorReportAction::run()->status;

    $abandoned = $workspace->create();
    touch($abandoned, Date::now()->getTimestamp() - MarketplaceComposerAuthWorkspace::staleAfterSeconds() - 60);

    try {
        $report = BuildMarketplaceOperationsDoctorReportAction::run();
        $check = $report->checks->firstWhere('id', 'marketplace.operations.composer-auth-files');

        // Debris present is a real failure of this check and it says so. The
        // next install sweeps it and the operator has nothing to do, so the
        // check is Warning-severity and the report status does not move.
        expect($check?->passed)->toBeFalse()
            ->and($check?->severity)->toBe(DoctorCheckSeverity::Warning)
            ->and($check?->isCriticalFailure())->toBeFalse()
            ->and($check?->evidence['count'])->toBe(1)
            ->and($report->status)->toBe($statusWithoutDebris);
    } finally {
        $workspace->removeDirectory($abandoned);
    }
});
