<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Data\Diagnostics\ServerSettingRecommendationData;
use Capell\Core\Data\Diagnostics\ServerSettingsData;
use Capell\Core\Data\Diagnostics\ServerSettingsRecommendationsData;
use Capell\Core\Enums\Diagnostics\ServerSettingFlag;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Compares measured PHP, opcache, FPM, queue and memory values with the host's
 * cores and RAM and names the exact change to make for each one that is off.
 *
 * @method static ServerSettingsRecommendationsData run(ServerSettingsData $settings)
 */
final class BuildServerSettingsRecommendationsAction
{
    use AsFake;
    use AsObject;

    private const int MIB = 1024 * 1024;

    /** Values PHP rounds opcache.max_accelerated_files up to. */
    private const array FILE_TABLE_SIZES = [3907, 7963, 16229, 32531, 65407, 130987, 262143, 1000000];

    /** Idle workers need this much history: a quiet weekend must not look like an idle week. */
    private const int IDLE_HISTORY_HOURS = 72;

    private const array GROUPS = ['opcache', 'pm.max_children', 'swap', 'queue:work processes'];

    public function handle(ServerSettingsData $settings): ServerSettingsRecommendationsData
    {
        $recommendations = [];
        $unmeasured = [];

        foreach ([
            $this->validateTimestamps($settings),
            $this->opcacheMemory($settings),
            $this->internedStrings($settings),
            $this->maxAcceleratedFiles($settings),
        ] as $recommendation) {
            if ($recommendation instanceof ServerSettingRecommendationData) {
                $recommendations[] = $recommendation;
            }
        }

        if (! $settings->opcacheEnabled) {
            $unmeasured[] = 'opcache';
        }

        $fpm = $this->maxChildren($settings);

        if ($fpm === null) {
            $unmeasured[] = 'pm.max_children';
        } elseif ($fpm !== false) {
            $recommendations[] = $fpm;
        }

        if ($settings->swapPeakUsedPercent === null) {
            $unmeasured[] = 'swap';
        } elseif (($swap = $this->swap($settings)) instanceof ServerSettingRecommendationData) {
            $recommendations[] = $swap;
        }

        $queue = $this->idleQueueWorkers($settings);

        if ($queue === null) {
            $unmeasured[] = 'queue:work processes';
        } elseif ($queue !== false) {
            $recommendations[] = $queue;
        }

        return new ServerSettingsRecommendationsData($recommendations, $unmeasured, count($unmeasured) < count(self::GROUPS));
    }

    private function validateTimestamps(ServerSettingsData $settings): ?ServerSettingRecommendationData
    {
        if (! $settings->production || ! $settings->opcacheEnabled || $settings->validateTimestamps !== true) {
            return null;
        }

        return new ServerSettingRecommendationData(ServerSettingFlag::ValidateTimestamps, 'opcache.validate_timestamps', '1', '0');
    }

    private function opcacheMemory(ServerSettingsData $settings): ?ServerSettingRecommendationData
    {
        if (! $settings->opcacheEnabled || ! $this->nearlyFull($settings->opcacheMemoryUsedBytes, $settings->opcacheMemoryBytes)) {
            return null;
        }

        return new ServerSettingRecommendationData(
            ServerSettingFlag::OpcacheMemory,
            'opcache.memory_consumption',
            sprintf('%d of %d MB', intdiv((int) $settings->opcacheMemoryUsedBytes, self::MIB), intdiv((int) $settings->opcacheMemoryBytes, self::MIB)),
            (string) (intdiv((int) $settings->opcacheMemoryBytes, self::MIB) * 2),
        );
    }

    private function internedStrings(ServerSettingsData $settings): ?ServerSettingRecommendationData
    {
        if (! $settings->opcacheEnabled || ! $this->nearlyFull($settings->internedUsedBytes, $settings->internedBufferBytes)) {
            return null;
        }

        return new ServerSettingRecommendationData(
            ServerSettingFlag::OpcacheInternedStrings,
            'opcache.interned_strings_buffer',
            sprintf('%d of %d MB', intdiv((int) $settings->internedUsedBytes, self::MIB), intdiv((int) $settings->internedBufferBytes, self::MIB)),
            (string) (intdiv((int) $settings->internedBufferBytes, self::MIB) * 2),
        );
    }

    private function maxAcceleratedFiles(ServerSettingsData $settings): ?ServerSettingRecommendationData
    {
        if (! $settings->opcacheEnabled || ! $this->nearlyFull($settings->cachedScripts, $settings->maxAcceleratedFiles)) {
            return null;
        }

        $target = self::FILE_TABLE_SIZES[array_key_last(self::FILE_TABLE_SIZES)];

        foreach (self::FILE_TABLE_SIZES as $size) {
            if ($size >= (int) $settings->cachedScripts * 2) {
                $target = $size;

                break;
            }
        }

        return new ServerSettingRecommendationData(
            ServerSettingFlag::OpcacheMaxFiles,
            'opcache.max_accelerated_files',
            sprintf('%d of %d files', $settings->cachedScripts, $settings->maxAcceleratedFiles),
            (string) $target,
        );
    }

    /** Null when FPM could not be measured, false when it is within its limits. */
    private function maxChildren(ServerSettingsData $settings): ServerSettingRecommendationData|false|null
    {
        if ($settings->fpmMaxChildren === null || $settings->fpmWorkerRssBytes === null || $settings->fpmWorkerRssBytes <= 0 || $settings->memoryTotalBytes === null) {
            return null;
        }

        $budget = $settings->memoryTotalBytes * (float) config('capell.health.host.fpm.memory_share', 0.6);
        $memoryBound = (int) floor(($budget - (int) $settings->queueWorkerRssBytes) / $settings->fpmWorkerRssBytes);
        $coreBound = $settings->cores * (int) config('capell.health.host.fpm.max_children_per_core', 10);
        $supported = min($memoryBound, $coreBound);

        // A budget the queue workers already use up, or a bound smaller than the core count, says the inputs are off rather than that the pool should shrink to a handful.
        if ($memoryBound <= 0 || $supported < max($settings->cores, 2)) {
            return null;
        }

        if ($settings->fpmMaxChildren <= $supported) {
            return false;
        }

        return new ServerSettingRecommendationData(ServerSettingFlag::FpmMaxChildren, 'pm.max_children', (string) $settings->fpmMaxChildren, (string) $supported);
    }

    private function swap(ServerSettingsData $settings): ?ServerSettingRecommendationData
    {
        if ($settings->swapPeakUsedPercent === null || $settings->swapPeakUsedPercent < (float) config('capell.health.host.swap.warning_used_percent', 60)) {
            return null;
        }

        return new ServerSettingRecommendationData(ServerSettingFlag::SwapPressure, 'swap', number_format($settings->swapPeakUsedPercent, 0) . '%', '');
    }

    /** Null when the queue could not be measured well enough, false when the workers are busy or few. */
    private function idleQueueWorkers(ServerSettingsData $settings): ServerSettingRecommendationData|false|null
    {
        if ($settings->queueWorkers === null) {
            return null;
        }

        if ($settings->queueWorkers < 2) {
            return false;
        }

        if ($settings->queuePeakDepth === null || ($settings->queueHistoryHours ?? 0) < self::IDLE_HISTORY_HOURS) {
            return null;
        }

        if ($settings->queuePeakDepth !== 0) {
            return false;
        }

        return new ServerSettingRecommendationData(ServerSettingFlag::IdleQueueWorkers, 'queue:work processes', $settings->queueWorkers . ' workers', '1');
    }

    private function nearlyFull(?int $used, ?int $capacity): bool
    {
        return $used !== null && $capacity !== null && $capacity > 0 && $used / $capacity >= 0.9;
    }
}
