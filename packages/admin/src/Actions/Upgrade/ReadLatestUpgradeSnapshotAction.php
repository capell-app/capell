<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Admin\Data\Upgrade\UpgradeAdvisorySnapshotData;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\TableName;
use Capell\Core\Support\Url\ExternalHttpsUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ReadLatestUpgradeSnapshotAction
{
    use AsFake;
    use AsObject;

    private const string UPDATE_ADVISORY_SNAPSHOTS_TABLE = 'marketplace_update_advisory_snapshots';

    public function handle(): ?UpgradeAdvisorySnapshotData
    {
        if (! Schema::hasTable(TableName::resolve(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE))) {
            return null;
        }

        try {
            $snapshot = DB::table(TableName::resolve(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE))
                ->latest('checked_at')
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($snapshot === null) {
            return null;
        }

        return new UpgradeAdvisorySnapshotData(
            updates: $this->decodeNoticeList($snapshot->updates ?? null),
            advisories: $this->decodeNoticeList($snapshot->advisories ?? null),
            checked_at: filled($snapshot->checked_at ?? null)
                ? CarbonImmutable::parse($snapshot->checked_at)
                : null,
            capell_version: is_string($snapshot->capell_version ?? null)
                ? $snapshot->capell_version
                : null,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeNoticeList(mixed $value): array
    {
        if (is_array($value)) {
            return $this->withSafeUrls(array_values(array_filter($value, is_array(...))));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = JsonCodec::decodeArray($value);

        return $this->withSafeUrls(array_values(array_filter($decoded, is_array(...))));
    }

    /**
     * Snapshots are stored from a remote response and may predate the writer's own
     * validation, so every link field is checked again on the way out: only an absolute
     * https URL survives and the upgrade page renders no link for anything else.
     *
     * @param  array<int|string, mixed>  $notices
     * @return array<int, array<string, mixed>>
     */
    private function withSafeUrls(array $notices): array
    {
        /** @var array<int, array<string, mixed>> */
        return array_map($this->safeUrlsIn(...), $notices);
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function safeUrlsIn(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->safeUrlsIn($item);

                continue;
            }

            if (is_string($key) && preg_match('/(^|_)url$/i', $key) === 1) {
                $value[$key] = ExternalHttpsUrl::sanitise($item);
            }
        }

        return $value;
    }
}
