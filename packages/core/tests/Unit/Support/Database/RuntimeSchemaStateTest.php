<?php

declare(strict_types=1);

use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Support\Facades\Schema;

it('memoizes table existence checks', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_extensions')
        ->andReturnTrue();

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeTrue()
        ->and($state->hasTable('capell_extensions'))->toBeTrue();
});

it('refreshes table existence checks when requested', function (): void {
    Schema::shouldReceive('hasTable')
        ->twice()
        ->with('capell_extensions')
        ->andReturn(false, true);

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->refreshTable('capell_extensions'))->toBeTrue();
});

it('memoizes column existence checks', function (): void {
    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('layouts', 'containers')
        ->andReturnTrue();

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeTrue()
        ->and($state->hasColumn('layouts', 'containers'))->toBeTrue();
});

it('refreshes column existence checks when requested', function (): void {
    Schema::shouldReceive('hasColumn')
        ->twice()
        ->with('layouts', 'containers')
        ->andReturn(false, true);

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse()
        ->and($state->refreshColumn('layouts', 'containers'))->toBeTrue();
});

it('returns false when schema table probing throws', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_extensions')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse();
});

it('returns false when schema column probing throws', function (): void {
    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('layouts', 'containers')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse();
});

it('forgets memoized table and column state', function (): void {
    Schema::shouldReceive('hasTable')
        ->twice()
        ->with('capell_extensions')
        ->andReturn(false, true);

    Schema::shouldReceive('hasColumn')
        ->twice()
        ->with('layouts', 'containers')
        ->andReturn(false, true);

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->hasColumn('layouts', 'containers'))->toBeFalse();

    $state->forgetTable('capell_extensions');
    $state->forgetColumn('layouts', 'containers');

    expect($state->hasTable('capell_extensions'))->toBeTrue()
        ->and($state->hasColumn('layouts', 'containers'))->toBeTrue();
});

it('forgets memoized columns when table state is forgotten', function (): void {
    Schema::shouldReceive('hasColumn')
        ->twice()
        ->with('layouts', 'containers')
        ->andReturn(false, true);

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse();

    $state->forgetTable('layouts');

    expect($state->hasColumn('layouts', 'containers'))->toBeTrue();
});

it('flushes all memoized schema state', function (): void {
    Schema::shouldReceive('hasTable')
        ->twice()
        ->with('capell_extensions')
        ->andReturn(false, true);

    Schema::shouldReceive('hasColumn')
        ->twice()
        ->with('layouts', 'containers')
        ->andReturn(false, true);

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->hasColumn('layouts', 'containers'))->toBeFalse();

    $state->flush();

    expect($state->hasTable('capell_extensions'))->toBeTrue()
        ->and($state->hasColumn('layouts', 'containers'))->toBeTrue();
});

it('primes table state for repeated runtime schema checks', function (): void {
    $schema = new RuntimeSchemaState;

    $tables = $schema->primeTables(['users', 'users', 'missing_runtime_schema_state_test_table']);

    expect($tables)
        ->toHaveKey('users', true)
        ->toHaveKey('missing_runtime_schema_state_test_table', false)
        ->and($schema->hasTable('users'))->toBeTrue()
        ->and($schema->hasTable('missing_runtime_schema_state_test_table'))->toBeFalse();
});
