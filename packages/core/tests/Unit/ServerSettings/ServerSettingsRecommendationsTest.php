<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\BuildServerSettingsRecommendationsAction;
use Capell\Core\Contracts\Health\ServerSettingsReader;
use Capell\Core\Data\Diagnostics\ServerSettingRecommendationData;
use Capell\Core\Data\Diagnostics\ServerSettingsData;
use Capell\Core\Data\Diagnostics\ServerSettingsRecommendationsData;
use Capell\Core\Enums\Diagnostics\ServerSettingFlag;

/**
 * A fixture host: a 4-core, 8 GB production server with every setting inside its limits.
 *
 * @param  array<string, mixed>  $overrides
 */
function serverSettingsReader(array $overrides = []): ServerSettingsReader
{
    $mib = 1024 ** 2;

    $data = new ServerSettingsData(...[
        'cores' => 4,
        'memoryTotalBytes' => 8 * 1024 * $mib,
        'swapPeakUsedPercent' => 0.0,
        'production' => true,
        'opcacheEnabled' => true,
        'validateTimestamps' => false,
        'opcacheMemoryBytes' => 256 * $mib,
        'opcacheMemoryUsedBytes' => 120 * $mib,
        'internedBufferBytes' => 16 * $mib,
        'internedUsedBytes' => 4 * $mib,
        'maxAcceleratedFiles' => 16229,
        'cachedScripts' => 5000,
        'fpmMaxChildren' => 12,
        'fpmWorkerRssBytes' => 135 * $mib,
        'queueWorkers' => 1,
        'queueWorkerRssBytes' => 135 * $mib,
        'queuePeakDepth' => 40,
        'queueHistoryHours' => 168,
        ...$overrides,
    ]);

    return new readonly class($data) implements ServerSettingsReader
    {
        public function __construct(private ServerSettingsData $data) {}

        public function read(): ServerSettingsData
        {
            return $this->data;
        }
    };
}

/** @param  array<string, mixed>  $overrides */
function recommendationsFor(array $overrides = []): ServerSettingsRecommendationsData
{
    return BuildServerSettingsRecommendationsAction::run(serverSettingsReader($overrides)->read());
}

it('recommends nothing for a healthy host', function (): void {
    $result = recommendationsFor();

    expect($result->recommendations)->toBe([])->and($result->unmeasured)->toBe([]);
});

it('flags opcache.validate_timestamps left on in production', function (): void {
    $result = recommendationsFor(['validateTimestamps' => true]);

    expect($result->recommendations)->toHaveCount(1)
        ->and($result->recommendations[0]->flag)->toBe(ServerSettingFlag::ValidateTimestamps)
        ->and($result->recommendations[0]->setting)->toBe('opcache.validate_timestamps')
        ->and($result->recommendations[0]->measured)->toBe('1')
        ->and($result->recommendations[0]->recommended)->toBe('0');
});

it('leaves opcache.validate_timestamps alone outside production', function (): void {
    expect(recommendationsFor(['validateTimestamps' => true, 'production' => false])->recommendations)->toBe([]);
});

it('flags an undersized opcache memory, interned strings buffer and file table', function (): void {
    $mib = 1024 ** 2;
    $result = recommendationsFor([
        'opcacheMemoryBytes' => 128 * $mib,
        'opcacheMemoryUsedBytes' => 125 * $mib,
        'internedBufferBytes' => 8 * $mib,
        'internedUsedBytes' => 8 * $mib,
        'maxAcceleratedFiles' => 7963,
        'cachedScripts' => 7900,
    ]);

    $byFlag = collect($result->recommendations)->keyBy(static fn (ServerSettingRecommendationData $r): string => $r->flag->value);
    $pick = static fn (ServerSettingFlag $flag): ServerSettingRecommendationData => $byFlag->get($flag->value) ?? throw new LogicException($flag->value . ' was not recommended');

    expect($byFlag->keys()->all())->toBe([
        ServerSettingFlag::OpcacheMemory->value,
        ServerSettingFlag::OpcacheInternedStrings->value,
        ServerSettingFlag::OpcacheMaxFiles->value,
    ])
        ->and($pick(ServerSettingFlag::OpcacheMemory)->setting)->toBe('opcache.memory_consumption')
        ->and($pick(ServerSettingFlag::OpcacheMemory)->measured)->toBe('125 of 128 MB')
        ->and($pick(ServerSettingFlag::OpcacheMemory)->recommended)->toBe('256')
        ->and($pick(ServerSettingFlag::OpcacheInternedStrings)->recommended)->toBe('16')
        ->and($pick(ServerSettingFlag::OpcacheMaxFiles)->measured)->toBe('7900 of 7963 files')
        ->and($pick(ServerSettingFlag::OpcacheMaxFiles)->recommended)->toBe('16229');
});

it('flags pm.max_children beyond what the memory supports', function (): void {
    // 60% of 8 GB is 4915 MB: 36 workers of 135 MB, less one queue worker, leaves 35.
    $result = recommendationsFor(['cores' => 8, 'fpmMaxChildren' => 60]);

    expect($result->recommendations)->toHaveCount(1)
        ->and($result->recommendations[0]->flag)->toBe(ServerSettingFlag::FpmMaxChildren)
        ->and($result->recommendations[0]->setting)->toBe('pm.max_children')
        ->and($result->recommendations[0]->measured)->toBe('60')
        ->and($result->recommendations[0]->recommended)->toBe('35');
});

it('caps pm.max_children by cores when memory would allow more', function (): void {
    $result = recommendationsFor(['cores' => 1, 'memoryTotalBytes' => 32 * 1024 ** 3, 'fpmMaxChildren' => 20]);

    expect($result->recommendations[0]->recommended)->toBe('10');
});

it('says pm.max_children is not measured instead of guessing when FPM cannot be read', function (): void {
    $result = recommendationsFor(['fpmMaxChildren' => null, 'fpmWorkerRssBytes' => null]);

    expect($result->recommendations)->toBe([])->and($result->unmeasured)->toBe(['pm.max_children']);
});

it('says pm.max_children is not measured when worker memory is unknown', function (): void {
    $result = recommendationsFor(['fpmMaxChildren' => 200, 'fpmWorkerRssBytes' => null]);

    expect($result->recommendations)->toBe([])->and($result->unmeasured)->toBe(['pm.max_children']);
});

it('flags swap pressure', function (): void {
    $result = recommendationsFor(['swapPeakUsedPercent' => 72.4]);

    expect($result->recommendations)->toHaveCount(1)
        ->and($result->recommendations[0]->flag)->toBe(ServerSettingFlag::SwapPressure)
        ->and($result->recommendations[0]->measured)->toBe('72%');
});

it('flags idle queue workers when no job has waited in a week', function (): void {
    $result = recommendationsFor(['queueWorkers' => 4, 'queueWorkerRssBytes' => 540 * 1024 ** 2, 'queuePeakDepth' => 0]);

    expect($result->recommendations)->toHaveCount(1)
        ->and($result->recommendations[0]->flag)->toBe(ServerSettingFlag::IdleQueueWorkers)
        ->and($result->recommendations[0]->measured)->toBe('4 workers')
        ->and($result->recommendations[0]->recommended)->toBe('1');
});

it('does not call a single worker idle', function (): void {
    expect(recommendationsFor(['queueWorkers' => 1, 'queuePeakDepth' => 0])->recommendations)->toBe([]);
});

it('does not flag opcache when it is disabled or unreadable', function (): void {
    $result = recommendationsFor([
        'opcacheEnabled' => false,
        'validateTimestamps' => null,
        'opcacheMemoryBytes' => null,
        'opcacheMemoryUsedBytes' => null,
        'internedBufferBytes' => null,
        'internedUsedBytes' => null,
        'maxAcceleratedFiles' => null,
        'cachedScripts' => null,
    ]);

    expect($result->recommendations)->toBe([]);
});

it('subtracts the memory the queue workers use, not their count, from the pm.max_children budget', function (): void {
    // 4915 MB budget less 600 MB of queue workers leaves 4315 MB: 31 workers of 135 MB. Counting two workers would give 34.
    $result = recommendationsFor(['cores' => 8, 'fpmMaxChildren' => 60, 'queueWorkers' => 2, 'queueWorkerRssBytes' => 600 * 1024 ** 2]);

    expect($result->recommendations[0]->recommended)->toBe('31');
});

it('does not advise a tiny pm.max_children when queue workers use all the memory budget', function (): void {
    $result = recommendationsFor(['fpmMaxChildren' => 60, 'queueWorkers' => 40, 'queueWorkerRssBytes' => 40 * 135 * 1024 ** 2]);

    expect($result->recommendations)->toBe([])->and($result->unmeasured)->toBe(['pm.max_children']);
});

it('treats a pm.max_children bound below the core count as not measured', function (): void {
    $result = recommendationsFor(['cores' => 8, 'memoryTotalBytes' => 1024 ** 3, 'fpmMaxChildren' => 60]);

    expect($result->recommendations)->toBe([])->and($result->unmeasured)->toBe(['pm.max_children']);
});

it('needs three days of queue history before calling workers idle', function (): void {
    $result = recommendationsFor(['queueWorkers' => 4, 'queuePeakDepth' => 0, 'queueHistoryHours' => 48]);

    expect($result->recommendations)->toBe([])->and($result->unmeasured)->toBe(['queue:work processes']);
});

it('lists every value it could not read and reports that nothing was measured', function (): void {
    $result = recommendationsFor([
        'opcacheEnabled' => false,
        'fpmMaxChildren' => null,
        'fpmWorkerRssBytes' => null,
        'swapPeakUsedPercent' => null,
        'queueWorkers' => null,
        'queuePeakDepth' => null,
        'queueHistoryHours' => null,
    ]);

    expect($result->recommendations)->toBe([])
        ->and($result->unmeasured)->toBe(['opcache', 'pm.max_children', 'swap', 'queue:work processes'])
        ->and($result->anyMeasured)->toBeFalse();
});

it('reports a measured value when only some readings are missing', function (): void {
    expect(recommendationsFor(['swapPeakUsedPercent' => null])->anyMeasured)->toBeTrue();
});
