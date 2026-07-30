<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

it('surfaces schema probe failures instead of silently dropping lifecycle writes', function (Closure $operation): void {
    Schema::shouldReceive('hasTable')
        ->once()
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
        ->once()
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
