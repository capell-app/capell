<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RunArtisanRuntimeRefreshStageAction;
use Capell\Tests\Support\Fakes\DeletionFailureFilesystem;
use Capell\Tests\Support\Fakes\FakeConsoleKernel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

it('turns a successful artisan refresh command into a passed stage', function (): void {
    $artisan = new FakeConsoleKernel;
    $artisan->returns('view:clear', 0);

    $files = new Filesystem;
    $root = sys_get_temp_dir() . '/runtime-refresh-empty-views-' . uniqid();
    $files->ensureDirectoryExists($root);
    config(['view.compiled' => $root]);

    try {
        $stage = new RunArtisanRuntimeRefreshStageAction($artisan)->handle('views', 'Views', 'view:clear');

        expect($stage->key)->toBe('views')
            ->and($stage->passed)->toBeTrue($stage->message);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('turns artisan failures and thrown errors into failed stages', function (): void {
    $failedArtisan = new FakeConsoleKernel;
    $failedArtisan->returns('view:clear', 1, 'diagnostic output');

    $failed = new RunArtisanRuntimeRefreshStageAction($failedArtisan)->handle('views', 'Views', 'view:clear');

    $throwingArtisan = new FakeConsoleKernel;
    $throwingArtisan->returns('view:clear', new RuntimeException('command failed'));

    $thrown = new RunArtisanRuntimeRefreshStageAction($throwingArtisan)->handle('views', 'Views', 'view:clear');

    expect($failed->passed)->toBeFalse()
        ->and($thrown->passed)->toBeFalse()
        ->and($thrown->key)->toBe('views')
        ->and($failed->message)->toContain('diagnostic output')
        ->and($thrown->message)->toContain('command failed');
});

it('fails the compiled view stage when cache files remain after a successful command', function (bool $reportedSuccess, bool $directory): void {
    $files = new Filesystem;
    $root = storage_path('framework/testing/compiled-view-removal-' . uniqid());
    $path = $root . '/stale';
    $files->ensureDirectoryExists($directory ? $path : $root);
    $files->put($directory ? $path . '/view.php' : $path, 'stale compiled view');
    config(['view.compiled' => $root]);
    $failingFiles = new DeletionFailureFilesystem($path, $reportedSuccess);
    app()->instance('files', $failingFiles);
    app()->instance(Filesystem::class, $failingFiles);

    try {
        $stage = new RunArtisanRuntimeRefreshStageAction(resolve(Kernel::class))->handle('views', 'Compiled views', 'view:clear');

        expect($stage->passed)->toBeFalse()
            ->and($stage->message)->toContain(__('capell-core::runtime-refresh.cache_refresh_required'))
            ->and($files->exists($path))->toBeTrue();
    } finally {
        app()->instance('files', $files);
        app()->instance(Filesystem::class, $files);
        $files->deleteDirectory($root);
    }
})->with([false, true])->with([false, true]);
