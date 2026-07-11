<?php

declare(strict_types=1);

use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Livewire\Blaze\BlazeServiceProvider;
use Livewire\Blaze\Config as BlazeConfig;

it('registers frontend anonymous components with Blaze', function (): void {
    app()->register(BlazeServiceProvider::class);

    $file = __DIR__ . '/../../resources/views/components/layout/index.blade.php';

    expect(file_exists($file))->toBeTrue();
    expect(RegisterBlazeOptimizedViewsAction::run($file))->toBeTrue();
    expect(resolve(BlazeConfig::class)->shouldCompile($file))->toBeTrue();
});
