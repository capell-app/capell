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
