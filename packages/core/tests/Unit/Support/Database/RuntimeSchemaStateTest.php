<?php

declare(strict_types=1);

use Capell\Core\Enums\SchemaProbeResult;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Support\Facades\Log;
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

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->tableResult('capell_extensions'))->toBe(SchemaProbeResult::Failed);
});

it('returns false when schema column probing throws', function (): void {
    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('layouts', 'containers')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse()
        ->and($state->columnResult('layouts', 'containers'))->toBe(SchemaProbeResult::Failed);
});

it('throws a dedicated exception for strict table probes without repeating a memoized failure', function (): void {
    $probeFailure = new RuntimeException('database unavailable');

    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_extensions')
        ->andThrow($probeFailure);

    $state = new RuntimeSchemaState;

    expect(fn (): bool => $state->hasTableOrFail('capell_extensions'))
        ->toThrow(
            SchemaProbeFailedException::class,
            'Unable to determine whether database table [capell_extensions] exists.',
        );

    try {
        $state->hasTableOrFail('capell_extensions');
    } catch (SchemaProbeFailedException $exception) {
        expect($exception->getPrevious())->toBe($probeFailure);
    }
});

it('throws a dedicated exception for strict column probes', function (): void {
    $probeFailure = new RuntimeException('database unavailable');

    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('layouts', 'containers')
        ->andThrow($probeFailure);

    $state = new RuntimeSchemaState;

    try {
        $state->hasColumnOrFail('layouts', 'containers');
    } catch (SchemaProbeFailedException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to determine whether database column [layouts.containers] exists.')
            ->and($exception->getPrevious())->toBe($probeFailure);

        return;
    }

    test()->fail('Expected a schema probe failure.');
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

it('logs a failed table probe so it is distinguishable from genuine absence', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_extensions')
        ->andThrow(new RuntimeException('database unavailable'));

    Log::spy();

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse();

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'runtime schema probe failed')
                && $context['table'] === 'capell_extensions'
                && $context['exception'] === RuntimeException::class
                && $context['reason'] === 'database unavailable');
});

it('logs a failed column probe so it is distinguishable from genuine absence', function (): void {
    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('layouts', 'containers')
        ->andThrow(new RuntimeException('database unavailable'));

    Log::spy();

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse();

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'runtime schema probe failed')
                && $context['table'] === 'layouts'
                && $context['column'] === 'containers');
});

it('logs nothing when the schema is genuinely absent', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_extensions')
        ->andReturnFalse();

    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('layouts', 'containers')
        ->andReturnFalse();

    Log::spy();

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->hasColumn('layouts', 'containers'))->toBeFalse();

    Log::getFacadeRoot()->shouldNotHaveReceived('warning');
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
