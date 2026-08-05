<?php

declare(strict_types=1);

use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Support\ProcessMarketplaceComposerScriptRunner;
use Illuminate\Support\Facades\File;

/**
 * Point the application root at a throwaway directory holding the given
 * composer.json, so the runner reads a manifest this test owns rather than the
 * repository's own.
 */
function withMarketplaceScriptRunnerApplicationManifest(?array $manifest, Closure $assertions): void
{
    $applicationPath = sys_get_temp_dir() . '/capell-marketplace-script-app-' . bin2hex(random_bytes(6));
    mkdir($applicationPath, 0755, true);

    if ($manifest !== null) {
        file_put_contents(
            $applicationPath . '/composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    $previousBasePath = base_path();
    app()->setBasePath($applicationPath);

    try {
        $assertions();
    } finally {
        app()->setBasePath($previousBasePath);
        File::deleteDirectory($applicationPath);
    }
}

it('does nothing when the application declares no post-autoload-dump scripts', function (): void {
    withMarketplaceScriptRunnerApplicationManifest(
        ['name' => 'capell-app/example'],
        function (): void {
            expect(new ProcessMarketplaceComposerScriptRunner()->replayRootScript(
                MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP,
                5,
            ))->toBeNull();
        },
    );
});

it('replays whatever the application declared, without knowing what the hooks are', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-script-bin-' . bin2hex(random_bytes(4));
    mkdir($binDirectory, 0755, true);

    $composerPath = $binDirectory . '/composer';
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
printf '%s\n' "$@"
exit 0
SH);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    putenv('PATH=' . $binDirectory);

    try {
        withMarketplaceScriptRunnerApplicationManifest(
            [
                'name' => 'capell-app/example',
                'scripts' => [
                    'post-autoload-dump' => [
                        '@php artisan package:discover --ansi',
                        '@php artisan some-application-specific:hook',
                    ],
                ],
            ],
            function (): void {
                $result = new ProcessMarketplaceComposerScriptRunner()->replayRootScript(
                    MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP,
                    30,
                );

                // Composer is handed the event name, not a list of commands
                // Capell reconstructed: the application owns that list.
                expect($result?->successful())->toBeTrue()
                    ->and($result?->output)->toContain('run-script')
                    ->and($result?->output)->toContain('post-autoload-dump');
            },
        );
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }
});

it('replays in the application root under the same composer environment as the require run', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-script-bin-' . bin2hex(random_bytes(4));
    mkdir($binDirectory, 0755, true);

    $composerPath = $binDirectory . '/composer';
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
echo "CWD=$(pwd -P)"
echo "COMPOSER_CACHE_DIR=$COMPOSER_CACHE_DIR"
echo "COMPOSER_HOME=$COMPOSER_HOME"
echo "COMPOSER_MEMORY_LIMIT=$COMPOSER_MEMORY_LIMIT"
echo "HTTPS_PROXY=$HTTPS_PROXY"
echo "AMBIENT_CREDENTIAL_CHARS=${#GITHUB_TOKEN}"
exit 0
SH);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    $previousProxy = getenv('HTTPS_PROXY');
    $previousGithubToken = getenv('GITHUB_TOKEN');
    putenv('PATH=' . $binDirectory);
    putenv('HTTPS_PROXY=http://proxy.test:3129');
    putenv('GITHUB_TOKEN=ambient-secret');

    try {
        withMarketplaceScriptRunnerApplicationManifest(
            [
                'name' => 'capell-app/example',
                'scripts' => ['post-autoload-dump' => ['@php artisan package:discover --ansi']],
            ],
            function (): void {
                $result = new ProcessMarketplaceComposerScriptRunner()->replayRootScript(
                    MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP,
                    30,
                );

                // The replay has to see what the require run saw: the same
                // application root, the same warm cache, the same proxy — and
                // none of the ambient credentials the require run strips.
                expect($result?->output)->toContain('CWD=' . realpath(base_path()))
                    ->and($result?->output)->toContain('COMPOSER_CACHE_DIR=' . storage_path('framework/composer/cache'))
                    ->and($result?->output)->toContain('COMPOSER_HOME=' . storage_path('framework/composer'))
                    ->and($result?->output)->toContain('COMPOSER_MEMORY_LIMIT=-1')
                    ->and($result?->output)->toContain('HTTPS_PROXY=http://proxy.test:3129')
                    // Counted rather than echoed: the redactor would rewrite a
                    // line whose key looks like a credential, which is exactly
                    // the behaviour the next test pins.
                    ->and($result?->output)->toContain('AMBIENT_CREDENTIAL_CHARS=0');
            },
        );
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        putenv($previousProxy === false ? 'HTTPS_PROXY' : 'HTTPS_PROXY=' . $previousProxy);
        putenv($previousGithubToken === false ? 'GITHUB_TOKEN' : 'GITHUB_TOKEN=' . $previousGithubToken);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }
});

it('redacts credentials a replayed application hook prints', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-script-bin-' . bin2hex(random_bytes(4));
    mkdir($binDirectory, 0755, true);

    $composerPath = $binDirectory . '/composer';
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
echo "GITHUB_TOKEN=ghp_hook_secret"
echo "hook failed, password=hunter2" >&2
exit 3
SH);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    putenv('PATH=' . $binDirectory);

    try {
        withMarketplaceScriptRunnerApplicationManifest(
            [
                'name' => 'capell-app/example',
                'scripts' => ['post-autoload-dump' => ['@php artisan some-application-specific:hook']],
            ],
            function (): void {
                $result = new ProcessMarketplaceComposerScriptRunner()->replayRootScript(
                    MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP,
                    30,
                );

                // A failed replay is reported, so its output reaches the error
                // reporter. The application owns these hooks and Capell cannot
                // see what they print.
                expect($result?->exitCode)->toBe(3)
                    ->and($result?->output)->not->toContain('ghp_hook_secret')
                    ->and($result?->output)->toContain('[redacted]')
                    ->and($result?->errorOutput)->not->toContain('hunter2')
                    ->and($result?->errorOutput)->toContain('[redacted]');
            },
        );
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }
});
