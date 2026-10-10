<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Tests\Support\Fakes\LegacyTimestampConnection;
use Capell\Tests\Support\Fakes\LegacyTimestampResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

it('removes legacy automatic updates from every Core event timestamp while preserving column attributes', function (): void {
    $targets = [
        'capell_upgrade_log' => 'ran_at', 'capell_upgrade_run_events' => 'occurred_at',
        'content_locks' => 'expires_at', 'layout_content_snapshots' => 'taken_at',
        'blueprint_schema_snapshots' => 'taken_at', 'stored_events' => 'created_at',
        'page_revisions' => 'occurred_at', 'metric_collection_runs' => 'started_at',
        'activity_buckets' => 'bucket_started_at', 'editor_scratch_drafts' => 'saved_at',
        'activity_visitors' => 'first_seen_at',
    ];
    $columns = [];
    foreach ($targets as $table => $column) {
        $columns[$table] = [(object) [
            'name' => $column, 'type' => 'timestamp(3)', 'nullable' => 'NO',
            'default' => '2020-01-01 00:00:00.123', 'comment' => 'Original event time',
            'extra' => 'on update current_timestamp(3)',
        ]];
    }

    $connection = new LegacyTimestampConnection($columns);
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    $original = DB::getFacadeRoot();
    // Historical migrations must execute without package services or configuration.
    DB::swap(new LegacyTimestampResolver($connection));
    try {
        $migration->up();
        $expected = [];
        foreach ($targets as $table => $column) {
            $expected[] = sprintf("alter table `proof_%s` modify `%s` timestamp(3) not null default '2020-01-01 00:00:00.123' comment 'Original event time'", $table, $column);
        }

        expect($connection->statements)->toBe($expected);
        $migration->down();
        expect($connection->statements)->toBe($expected);
    } finally {
        DB::swap($original);
    }
});

it('is a no-op on SQLite and does not reverse the timestamp safety repair', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    expect($migration)->toBeInstanceOf(Migration::class);
    $connection = resolve(ConnectionResolverInterface::class)->connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $migration->up();
    $migration->down();

    expect($connection->getQueryLog())->toBe([]);
});

it('does not require timestamp repair from third-party schema dialects', function (): void {
    expect(new ReflectionClass(DatabaseSchemaDialect::class)->hasMethod('dropImplicitTimestampUpdate'))->toBeFalse();
});

it('does not consult package schema dialects when running the published repair', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    $connection = resolve(ConnectionResolverInterface::class)->connection();
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->once()->andReturn(['sqlite']);
    $platform->shouldNotReceive('schemaDialect');
    CapellDatabase::swap(new DatabasePlatformRegistry([$platform]));
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $migration->up();

    expect($connection->getQueryLog())->toBe([]);
});
