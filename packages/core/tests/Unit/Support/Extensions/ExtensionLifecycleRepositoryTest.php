<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

it('records an install in the table that the configured prefix resolves to', function (): void {
    Schema::rename('capell_extensions', 'pfx_capell_extensions');
    Config::set('capell.database.table_prefix', 'pfx_');

    new ExtensionLifecycleRepository(new RuntimeSchemaState)->recordInstalled('vendor/example', null);

    expect(DB::table('pfx_capell_extensions')->where('composer_name', 'vendor/example')->exists())->toBeTrue();
});

it('clears without resolving the table name, so invalid table configuration cannot break a clear', function (): void {
    $repository = new ExtensionLifecycleRepository(new RuntimeSchemaState);
    Config::set('capell.database.table_prefix', ['not', 'a', 'string']);

    $repository->clear();

    expect(fn (): ?ExtensionStatusEnum => $repository->status('vendor/example', new Collection))
        ->toThrow(RuntimeException::class);
});
