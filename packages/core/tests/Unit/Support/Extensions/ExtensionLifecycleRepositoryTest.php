<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

it('surfaces schema probe failures instead of silently dropping lifecycle writes', function (Closure $operation): void {
    Schema::shouldReceive('hasTable')
        ->with('capell_extensions')
        ->andThrow(new RuntimeException('database unavailable'));

    $repository = new ExtensionLifecycleRepository(new RuntimeSchemaState);

    expect(fn (): mixed => $operation($repository))
        ->toThrow(SchemaProbeFailedException::class);
})->with([
    'install' => function (ExtensionLifecycleRepository $repository): void {
        $repository->recordInstalled('vendor/example', null);
    },
    'lifecycle' => function (ExtensionLifecycleRepository $repository): void {
        $repository->recordLifecycle(
            'vendor/example',
            ExtensionStatusEnum::Disabled,
            null,
        );
    },
    'delete' => function (ExtensionLifecycleRepository $repository): void {
        $repository->delete('vendor/example');
    },
]);

it('keeps extension reads fail closed when schema probing fails', function (Closure $operation): void {
    Schema::shouldReceive('hasTable')
        ->with('capell_extensions')
        ->andThrow(new RuntimeException('database unavailable'));

    $repository = new ExtensionLifecycleRepository(new RuntimeSchemaState);

    /** @var Collection<string, PackageData> $packages */
    $packages = new Collection;

    expect($operation($repository, $packages))->toBeNull();
})->with([
    'status' => fn (ExtensionLifecycleRepository $repository, Collection $packages): ?ExtensionStatusEnum => $repository->status(
        'vendor/example',
        $packages,
    ),
    'runtime gate' => fn (ExtensionLifecycleRepository $repository, Collection $packages): ?bool => $repository->runtimeGateAllows(
        'vendor/example',
        $packages,
    ),
]);

it('resolves the lifecycle table name once per repository and again after it is cleared', function (): void {
    Config::set('capell.database.table_prefix', 'first_');
    Schema::shouldReceive('hasTable')->with('first_capell_extensions')->once()->andReturn(false);
    Schema::shouldReceive('hasTable')->with('second_capell_extensions')->once()->andReturn(false);

    $repository = new ExtensionLifecycleRepository(new RuntimeSchemaState);

    /** @var Collection<string, PackageData> $packages */
    $packages = new Collection;

    // A bootstrap asks for package status thousands of times; the table name
    // must not be rebuilt from configuration on each probe.
    expect($repository->status('vendor/example', $packages))->toBeNull();

    Config::set('capell.database.table_prefix', 'second_');

    expect($repository->status('vendor/example', $packages))->toBeNull()
        ->and($repository->status('vendor/other', $packages))->toBeNull();

    $repository->clear();

    expect($repository->status('vendor/example', $packages))->toBeNull();
});
