<?php

declare(strict_types=1);

use Capell\Core\Enums\SchemaProbeResult;
use Capell\Core\Events\DatabaseSchemaChanged;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Tests\Support\SchemaDiagnosticRecorder;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->schemaDiagnostics = new SchemaDiagnosticRecorder;
    Event::listen(MessageLogged::class, $this->schemaDiagnostics->record(...));
});

it('preserves a table snapshot until explicitly refreshed', function (): void {
    $state = new RuntimeSchemaState;
    expect($state->hasTable('runtime_snapshot'))->toBeFalse();
    Schema::create('runtime_snapshot', fn (Blueprint $table) => $table->id());
    expect($state->hasTable('runtime_snapshot'))->toBeFalse()
        ->and($state->refreshTable('runtime_snapshot'))->toBeTrue();
});

it('preserves a present table snapshot after the table is dropped', function (): void {
    Schema::create('runtime_snapshot', fn (Blueprint $table) => $table->id());
    $state = new RuntimeSchemaState;
    expect($state->hasTable('runtime_snapshot'))->toBeTrue();
    Schema::drop('runtime_snapshot');
    expect($state->hasTable('runtime_snapshot'))->toBeTrue()
        ->and($state->refreshTable('runtime_snapshot'))->toBeFalse();
});

it('preserves a column snapshot until explicitly refreshed', function (): void {
    Schema::create('runtime_snapshot', fn (Blueprint $table) => $table->id());
    $state = new RuntimeSchemaState;
    expect($state->hasColumn('runtime_snapshot', 'label'))->toBeFalse();
    Schema::table('runtime_snapshot', fn (Blueprint $table) => $table->string('label'));
    expect($state->hasColumn('runtime_snapshot', 'label'))->toBeFalse()
        ->and($state->refreshColumn('runtime_snapshot', 'label'))->toBeTrue();
});

it('preserves a present column snapshot after the column is dropped', function (): void {
    Schema::create('runtime_snapshot', function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });
    $state = new RuntimeSchemaState;
    expect($state->hasColumn('runtime_snapshot', 'label'))->toBeTrue();
    Schema::table('runtime_snapshot', fn (Blueprint $table) => $table->dropColumn('label'));
    expect($state->hasColumn('runtime_snapshot', 'label'))->toBeTrue()
        ->and($state->refreshColumn('runtime_snapshot', 'label'))->toBeFalse();
});

it('returns false when schema table probing throws', function (): void {
    Schema::shouldReceive('hasTable')
        ->with('capell_extensions')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->tableResult('capell_extensions'))->toBe(SchemaProbeResult::Failed);
});

it('returns false when schema column probing throws', function (): void {
    Schema::shouldReceive('hasColumn')
        ->with('layouts', 'containers')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse()
        ->and($state->columnResult('layouts', 'containers'))->toBe(SchemaProbeResult::Failed);
});

it('throws a dedicated exception for strict table probes without repeating a memoized failure', function (): void {
    $probeFailure = new RuntimeException('database unavailable');

    Schema::shouldReceive('hasTable')
        ->with('capell_extensions')
        ->andThrow($probeFailure);

    $state = new RuntimeSchemaState;

    expect(fn (): bool => $state->hasTableOrFail('capell_extensions'))
        ->toThrow(
            SchemaProbeFailedException::class,
            'Unable to determine whether database table [capell_extensions] exists.',
        );

    Schema::shouldReceive('hasTable')->with('capell_extensions')->andReturnTrue();

    try {
        $state->hasTableOrFail('capell_extensions');
    } catch (SchemaProbeFailedException $schemaProbeFailedException) {
        expect($schemaProbeFailedException->getPrevious())->toBe($probeFailure);
    }
});

it('throws a dedicated exception for strict column probes', function (): void {
    $probeFailure = new RuntimeException('database unavailable');

    Schema::shouldReceive('hasColumn')
        ->with('layouts', 'containers')
        ->andThrow($probeFailure);

    $state = new RuntimeSchemaState;

    try {
        $state->hasColumnOrFail('layouts', 'containers');
    } catch (SchemaProbeFailedException $schemaProbeFailedException) {
        expect($schemaProbeFailedException->getMessage())
            ->toBe('Unable to determine whether database column [layouts.containers] exists.')
            ->and($schemaProbeFailedException->getPrevious())->toBe($probeFailure);

        return;
    }

    test()->fail('Expected a schema probe failure.');
});

it('forgets table and column snapshots on explicit invalidation', function (string $operation): void {
    $state = new RuntimeSchemaState;
    expect($state->hasTable('runtime_snapshot'))->toBeFalse()
        ->and($state->hasColumn('runtime_snapshot', 'label'))->toBeFalse();
    Schema::create('runtime_snapshot', function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });

    if ($operation === 'flush') {
        $state->flush();
    } elseif ($operation === 'table') {
        $state->forgetTable('runtime_snapshot');
    } else {
        $state->forgetColumn('runtime_snapshot', 'label');
    }

    expect($state->hasColumn('runtime_snapshot', 'label'))->toBeTrue()
        ->and($state->hasTable('runtime_snapshot'))->toBe($operation !== 'column');
})->with(['flush', 'table', 'column']);

it('logs a failed table probe so it is distinguishable from genuine absence', function (): void {
    Schema::shouldReceive('hasTable')
        ->with('capell_extensions')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse();

    expect($this->schemaDiagnostics->events())->toHaveCount(1)
        ->and($this->schemaDiagnostics->events()[0]->message)->toContain('runtime schema probe failed')
        ->and($this->schemaDiagnostics->events()[0]->context)->toMatchArray([
            'table' => 'capell_extensions', 'exception' => RuntimeException::class, 'reason' => 'database unavailable',
        ]);
});

it('logs a failed column probe so it is distinguishable from genuine absence', function (): void {
    Schema::shouldReceive('hasColumn')
        ->with('layouts', 'containers')
        ->andThrow(new RuntimeException('database unavailable'));

    $state = new RuntimeSchemaState;

    expect($state->hasColumn('layouts', 'containers'))->toBeFalse();

    expect($this->schemaDiagnostics->events())->toHaveCount(1)
        ->and($this->schemaDiagnostics->events()[0]->context)->toMatchArray(['table' => 'layouts', 'column' => 'containers']);
});

it('logs nothing when the schema is genuinely absent', function (): void {
    Schema::shouldReceive('hasTable')
        ->with('capell_extensions')
        ->andReturnFalse();

    Schema::shouldReceive('hasColumn')
        ->with('layouts', 'containers')
        ->andReturnFalse();

    $state = new RuntimeSchemaState;

    expect($state->hasTable('capell_extensions'))->toBeFalse()
        ->and($state->hasColumn('layouts', 'containers'))->toBeFalse();

    expect($this->schemaDiagnostics->events())->toBe([]);
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

/** @return list<string> the statements $run executed */
function runtimeSchemaQueries(Closure $run): array
{
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $run();

    return $queries;
}

it('answers every present table from one listing of the connection', function (): void {
    $tables = ['runtime_listed_one', 'runtime_listed_two', 'runtime_listed_three'];

    foreach ($tables as $table) {
        Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
    }

    $probe = static function (array $names): array {
        $state = new RuntimeSchemaState;
        $answers = [];
        $queries = runtimeSchemaQueries(function () use ($state, $names, &$answers): void {
            foreach ($names as $name) {
                $answers[$name] = $state->tableResult($name);
            }
        });

        return [$answers, $queries];
    };

    [, $oneTable] = $probe([$tables[0]]);
    [$answers, $threeTables] = $probe($tables);

    // The work is the listing, whatever the number of tables it answers.
    expect($answers)->each->toBe(SchemaProbeResult::Present)
        ->and($threeTables)->toHaveCount(count($oneTable))
        ->and(implode("\n", $threeTables))->not->toContain('runtime_listed_');
});

it('probes a table missing from the listing itself, so one created after the listing is found', function (): void {
    $state = new RuntimeSchemaState;

    expect($state->hasTable('users'))->toBeTrue();

    Schema::create('runtime_created_after_listing', fn (Blueprint $blueprint) => $blueprint->id());

    expect($state->hasTable('runtime_created_after_listing'))->toBeTrue()
        ->and($state->hasTable('runtime_never_created'))->toBeFalse();
});

it('lists the tables again after a table is forgotten or refreshed', function (string $operation): void {
    Schema::create('runtime_listed_then_dropped', fn (Blueprint $blueprint) => $blueprint->id());
    $state = new RuntimeSchemaState;

    expect($state->hasTable('runtime_listed_then_dropped'))->toBeTrue();

    Schema::drop('runtime_listed_then_dropped');

    if ($operation === 'forget') {
        $state->forgetTable('runtime_listed_then_dropped');
        expect($state->hasTable('runtime_listed_then_dropped'))->toBeFalse();
    } else {
        expect($state->refreshTable('runtime_listed_then_dropped'))->toBeFalse();
    }

    // A table dropped after the first listing is never reported from it again.
    expect($state->tableResult('runtime_listed_then_dropped'))->toBe(SchemaProbeResult::Absent);
})->with(['forget', 'refresh']);

it('probes each table itself when the listing cannot be read', function (): void {
    Schema::shouldReceive('getCurrentSchemaName')->andThrow(new RuntimeException('listing unavailable'));
    Schema::shouldReceive('hasTable')->with('capell_extensions')->andReturnTrue();
    Schema::shouldReceive('hasTable')->with('missing_runtime_table')->andReturnFalse();

    $state = new RuntimeSchemaState;

    expect($state->tableResult('capell_extensions'))->toBe(SchemaProbeResult::Present)
        ->and($state->tableResult('missing_runtime_table'))->toBe(SchemaProbeResult::Absent)
        ->and($this->schemaDiagnostics->events())->toBe([]);
});

it('forgets what it read once migrations have run in the same process', function (string $event): void {
    $state = resolve(RuntimeSchemaState::class);

    expect($state->hasTable('runtime_migrated_later'))->toBeFalse();

    Schema::create('runtime_migrated_later', fn (Blueprint $blueprint) => $blueprint->id());
    event($event === 'migrations' ? new MigrationsEnded('up') : new DatabaseSchemaChanged);

    expect($state->hasTable('runtime_migrated_later'))->toBeTrue();
})->with(['migrations', 'schema change']);
