<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;

it('adds the modern column once without changing legacy audit data', function (): void {
    $table = 'activity_log_upgrade_fixture';
    $model = new class extends Activity
    {
        use HasFactory;

        #[Override]
        public function getTable(): string
        {
            return 'activity_log_upgrade_fixture';
        }
    };
    config()->set('activitylog.activity_model', $model::class);
    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->json('properties')->nullable();
        $blueprint->uuid('batch_uuid')->nullable();
    });
    $properties = '{"old":{"name":"Before"},"attributes":{"name":"After"}}';
    DB::table($table)->insert(['properties' => $properties, 'batch_uuid' => 'ac56d3e7-a70f-45c7-9d29-ecb5dd3baf0e']);
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_05_000001_add_attribute_changes_to_activity_log_table.php';

    $migration->up();
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn($table, 'attribute_changes'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'batch_uuid'))->toBeTrue()
        ->and(DB::table($table)->sole()->properties)->toBe($properties)
        ->and(DB::table($table)->sole()->attribute_changes)->toBeNull();
});

it('keeps a missing-table upgrade pending and repairs a later vendor create through the real migrator', function (string $vendorTimestamp): void {
    $root = dirname(__DIR__, 5);
    $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', $root . '/packages/core/tests/fixtures/activitylog-migrator.php', $root, $vendorTimestamp]);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['recorded_before_vendor'])->toBeFalse()
        ->and($result['table_before_vendor'])->toBeFalse()
        ->and($result['recorded_after_vendor'])->toBeTrue()
        ->and($result['column_after_vendor'])->toBeTrue()
        ->and($result['recorded_count'])->toBe(1);
})->with(['vendor first' => '2025_08_02_100000', 'vendor later' => '2026_10_06_000001']);

it('defers a missing table and rejects bypassing the migrator deferral', function (): void {
    $model = new class extends Activity
    {
        use HasFactory;

        #[Override]
        public function getTable(): string
        {
            return 'activity_log_missing_fixture';
        }
    };
    config()->set('activitylog.activity_model', $model::class);
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_05_000001_add_attribute_changes_to_activity_log_table.php';

    expect($migration->shouldRun())->toBeFalse();
    expect(fn () => $migration->up())->toThrow(LogicException::class, 'The activity log table must exist before adding attribute_changes.');
});
