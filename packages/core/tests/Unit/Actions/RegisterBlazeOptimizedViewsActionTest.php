<?php

declare(strict_types=1);

use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Capell\Core\Data\BlazeOptimizationData;
use Illuminate\Support\Facades\File;
use Livewire\Blaze\BlazeServiceProvider;
use Livewire\Blaze\Config as BlazeConfig;

beforeEach(function (): void {
    app()->register(BlazeServiceProvider::class);

    resolve(BlazeConfig::class)->clear();
});

it('registers an existing component directory for Blaze compilation', function (): void {
    $directory = storage_path('framework/testing/blaze/action-components');
    File::ensureDirectoryExists($directory);
    File::put($directory . '/button.blade.php', '<button>{{ $slot }}</button>');

    config()->set('capell.blaze.enabled', true);
    config()->set('capell.blaze.debug', false);
    config()->set('capell.blaze.throw', false);

    $registered = RegisterBlazeOptimizedViewsAction::run($directory);

    expect($registered)->toBeTrue();
    expect(resolve(BlazeConfig::class)->shouldCompile($directory . '/button.blade.php'))->toBeTrue();
    expect(resolve(BlazeConfig::class)->shouldMemoize($directory . '/button.blade.php'))->toBeFalse();
    expect(resolve(BlazeConfig::class)->shouldFold($directory . '/button.blade.php'))->toBeFalse();
});

it('can opt a directory into memoization without folding', function (): void {
    $directory = storage_path('framework/testing/blaze/memo-components');
    File::ensureDirectoryExists($directory);
    File::put($directory . '/icon.blade.php', '<svg></svg>');

    config()->set('capell.blaze.enabled', true);

    $registered = RegisterBlazeOptimizedViewsAction::run(
        $directory,
        new BlazeOptimizationData(compile: true, memo: true, fold: false),
    );

    expect($registered)->toBeTrue();
    expect(resolve(BlazeConfig::class)->shouldCompile($directory . '/icon.blade.php'))->toBeTrue();
    expect(resolve(BlazeConfig::class)->shouldMemoize($directory . '/icon.blade.php'))->toBeTrue();
    expect(resolve(BlazeConfig::class)->shouldFold($directory . '/icon.blade.php'))->toBeFalse();
});

it('does not register directories when Capell Blaze support is disabled', function (): void {
    $directory = storage_path('framework/testing/blaze/disabled-components');
    File::ensureDirectoryExists($directory);
    File::put($directory . '/badge.blade.php', '<span>{{ $slot }}</span>');

    config()->set('capell.blaze.enabled', false);

    $registered = RegisterBlazeOptimizedViewsAction::run($directory);

    expect($registered)->toBeFalse();
    expect(resolve(BlazeConfig::class)->shouldCompile($directory . '/badge.blade.php'))->toBeFalse();
});

it('ignores missing component directories', function (): void {
    config()->set('capell.blaze.enabled', true);

    $registered = RegisterBlazeOptimizedViewsAction::run(
        storage_path('framework/testing/blaze/missing-components'),
    );

    expect($registered)->toBeFalse();
});
