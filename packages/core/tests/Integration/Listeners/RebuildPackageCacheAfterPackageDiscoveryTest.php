<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildPackageCacheAction;
use Capell\Core\Listeners\RebuildPackageCacheAfterPackageDiscovery;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function packageDiscoveryFinished(string $command, int $exitCode): CommandFinished
{
    return new CommandFinished($command, new ArrayInput([]), new NullOutput, $exitCode);
}

beforeEach(function (): void {
    $this->originalBootstrapPath = app()->bootstrapPath();
    $this->bootstrapPath = storage_path('framework/testing/package-discovery-' . bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($this->bootstrapPath . '/cache');
    app()->useBootstrapPath($this->bootstrapPath);
});

afterEach(function (): void {
    app()->useBootstrapPath($this->originalBootstrapPath);
    File::deleteDirectory($this->bootstrapPath);
});

it('rebuilds an existing package cache after Composer runs package:discover', function (): void {
    file_put_contents($this->bootstrapPath . '/cache/capell-package-manifests.php', '<?php return [];');
    BuildPackageCacheAction::shouldRun()->once();

    event(packageDiscoveryFinished('package:discover', 0));
});

it('reports a failed rebuild instead of failing the Composer script that ran package:discover', function (): void {
    file_put_contents($this->bootstrapPath . '/cache/capell-package-manifests.php', '<?php return [];');
    BuildPackageCacheAction::shouldRun()->once()->andThrow(new RuntimeException('Invalid package manifest.'));
    Exceptions::fake();

    resolve(RebuildPackageCacheAfterPackageDiscovery::class)->handle(packageDiscoveryFinished('package:discover', 0));

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Invalid package manifest.');
});

it('leaves the cache alone after other commands, failed discovery, or when no cache exists', function (): void {
    BuildPackageCacheAction::shouldRun()->never();
    $listener = resolve(RebuildPackageCacheAfterPackageDiscovery::class);

    $listener->handle(packageDiscoveryFinished('package:discover', 0));

    file_put_contents($this->bootstrapPath . '/cache/capell-package-manifests.php', '<?php return [];');
    $listener->handle(packageDiscoveryFinished('package:discover', 1));
    $listener->handle(packageDiscoveryFinished('migrate', 0));
});

it('rebuilds the cache during a production deploy, where composer install runs package:discover in the console', function (): void {
    $originalEnvironment = app()->environment();
    file_put_contents($this->bootstrapPath . '/cache/capell-package-manifests.php', '<?php return [];');
    app()->instance('env', 'production');

    try {
        expect(app()->environment('production'))->toBeTrue()
            ->and(app()->runningInConsole())->toBeTrue();

        // The deployed cache held no packages; the real rebuild must replace it with the installed set.
        event(packageDiscoveryFinished('package:discover', 0));

        $rebuilt = require $this->bootstrapPath . '/cache/capell-package-manifests.php';

        expect($rebuilt)->toBeArray()
            ->and($rebuilt)->toHaveKey('capell-app/core');
    } finally {
        app()->instance('env', $originalEnvironment);
    }
});
