<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\PrepareInstalledExtensionReloadAction;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Illuminate\Filesystem\Filesystem;

it('refuses an admin reload when cache deletion reports failure even if the file disappears', function (): void {
    $path = app()->getCachedRoutesPath();
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('exists')->with($path)->andReturn(true, false);
    $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
    app()->instance(PrepareInstalledExtensionReloadAction::class, new PrepareInstalledExtensionReloadAction(app(), $files));

    expect(function (): void {
        PrepareInstalledExtensionReloadAction::run();
    })->toThrow(RuntimeException::class, __('capell-admin::message.extension_activation_pending'));
    expect(resolve(InstalledRuntimeLifecycle::class)->isUnavailable())->toBeTrue();
});
