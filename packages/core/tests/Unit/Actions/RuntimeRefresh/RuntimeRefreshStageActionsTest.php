<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RunArtisanRuntimeRefreshStageAction;
use Illuminate\Contracts\Console\Kernel;

it('turns a successful artisan refresh command into a passed stage', function (): void {
    $artisan = Mockery::mock(Kernel::class);
    $artisan->expects('call')->once()->with('view:clear', ['--no-interaction' => true])->andReturn(0);
    $artisan->expects('output')->once()->andReturn('');

    $stage = new RunArtisanRuntimeRefreshStageAction($artisan)->handle('views', 'Views', 'view:clear');

    expect($stage->key)->toBe('views')
        ->and($stage->passed)->toBeTrue();
});

it('turns artisan failures and thrown errors into failed stages', function (): void {
    $failedArtisan = Mockery::mock(Kernel::class);
    $failedArtisan->expects('call')->once()->andReturn(1);
    $failedArtisan->expects('output')->once()->andReturn('diagnostic output');
    $failed = new RunArtisanRuntimeRefreshStageAction($failedArtisan)->handle('views', 'Views', 'view:clear');

    $throwingArtisan = Mockery::mock(Kernel::class);
    $throwingArtisan->expects('call')->once()->andThrow(new RuntimeException('command failed'));
    $thrown = new RunArtisanRuntimeRefreshStageAction($throwingArtisan)->handle('views', 'Views', 'view:clear');

    expect($failed->passed)->toBeFalse()
        ->and($thrown->passed)->toBeFalse()
        ->and($thrown->key)->toBe('views');
});
