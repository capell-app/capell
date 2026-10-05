<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

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

it('allows the vendor table to be created later during a fresh install', function (): void {
    $model = new class extends Activity
    {
        use HasFactory;

        #[Override]
        public function getTable(): string
        {
            return 'activity_log_not_installed';
        }
    };
    config()->set('activitylog.activity_model', $model::class);
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_05_000001_add_attribute_changes_to_activity_log_table.php';
    $migration->up();

    expect(Schema::hasTable($model->getTable()))->toBeFalse();
});
