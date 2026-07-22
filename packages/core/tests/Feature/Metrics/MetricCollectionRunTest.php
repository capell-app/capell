<?php

declare(strict_types=1);

use Capell\Core\Actions\Metrics\StoreMetricCollectionRunAction;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\MetricCollectionRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

it('stores typed started and completed collection runs with source identity', function (): void {
    $action = resolve(StoreMetricCollectionRunAction::class);
    $startedAt = CarbonImmutable::parse('2026-07-22 00:05:00');
    $run = $action->execute(
        day: '2026-07-21',
        ownerPackage: 'capell-app/site-stats',
        collectorKey: 'content_totals',
        definitionHash: str_repeat('a', 64),
        status: MetricCollectionRunStatus::Started,
        startedAt: $startedAt,
    );

    expect($run->status)->toBe(MetricCollectionRunStatus::Started)
        ->and($run->completed_at)->toBeNull();

    $action->execute(
        day: '2026-07-21',
        ownerPackage: 'capell-app/site-stats',
        collectorKey: 'content_totals',
        definitionHash: str_repeat('a', 64),
        status: MetricCollectionRunStatus::Completed,
        startedAt: $startedAt,
        completedAt: CarbonImmutable::parse('2026-07-22 00:06:00'),
        sourceWatermark: 'content-updated-at:2026-07-22T00:00:00Z',
        sourceChecksum: str_repeat('c', 64),
        run: $run,
    );

    expect($run->fresh())
        ->status->toBe(MetricCollectionRunStatus::Completed)
        ->source_checksum->toBe(str_repeat('c', 64))
        ->error_summary->toBeNull()
        ->completed_at->toBeInstanceOf(CarbonImmutable::class);
});

it('stores typed failed and unsupported outcomes with bounded summaries', function (MetricCollectionRunStatus $status): void {
    $run = resolve(StoreMetricCollectionRunAction::class)->execute(
        day: '2026-07-21',
        ownerPackage: 'capell-app/site-stats',
        collectorKey: 'content_totals',
        definitionHash: str_repeat('a', 64),
        status: $status,
        startedAt: CarbonImmutable::parse('2026-07-22 00:05:00'),
        completedAt: CarbonImmutable::parse('2026-07-22 00:05:01'),
        errorSummary: $status === MetricCollectionRunStatus::Failed
            ? 'Authoritative source was unavailable.'
            : 'Historical collection is unsupported.',
    );

    expect($run->status)->toBe($status)
        ->and($run->error_summary)->not->toBeEmpty()
        ->and($run->source_checksum)->toBeNull();
})->with([MetricCollectionRunStatus::Failed, MetricCollectionRunStatus::Unsupported]);

it('rejects invalid run lifecycle combinations and hashes at the model boundary', function (array $attributes, string $exception): void {
    expect(fn (): MetricCollectionRun => MetricCollectionRun::query()->create([
        'day' => '2026-07-21',
        'owner_package' => 'capell-app/site-stats',
        'collector_key' => 'content_totals',
        'definition_hash' => str_repeat('a', 64),
        'status' => MetricCollectionRunStatus::Started,
        'started_at' => '2026-07-22 00:05:00',
        ...$attributes,
    ]))->toThrow($exception);
})->with([
    'unknown state' => [['status' => 'unknown'], ValueError::class],
    'invalid definition hash' => [['definition_hash' => 'not-a-hash'], InvalidArgumentException::class],
    'started with completion' => [['completed_at' => '2026-07-22 00:06:00'], InvalidArgumentException::class],
    'completed without source' => [[
        'status' => MetricCollectionRunStatus::Completed,
        'completed_at' => '2026-07-22 00:06:00',
    ], InvalidArgumentException::class],
    'completed with invalid checksum' => [[
        'status' => MetricCollectionRunStatus::Completed,
        'completed_at' => '2026-07-22 00:06:00',
        'source_watermark' => 'source:1',
        'source_checksum' => 'invalid',
    ], InvalidArgumentException::class],
    'failed without summary' => [[
        'status' => MetricCollectionRunStatus::Failed,
        'completed_at' => '2026-07-22 00:06:00',
    ], InvalidArgumentException::class],
]);

it('registers metric migrations and protected storage tables', function (): void {
    expect(Schema::hasTable('metric_collection_runs'))->toBeTrue()
        ->and(CapellCore::getMigrations())
        ->toContain('2026_07_22_000001_create_metric_collection_runs_table')
        ->toContain('2026_07_22_000002_create_metric_daily_rollups_table')
        ->and(CapellCore::getProtectedTables())
        ->toContain('metric_collection_runs')
        ->toContain('metric_daily_rollups');
});
