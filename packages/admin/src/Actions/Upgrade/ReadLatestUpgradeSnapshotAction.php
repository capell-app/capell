<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Admin\Data\Upgrade\UpgradeAdvisorySnapshotData;
use Capell\Core\Support\Json\JsonCodec;
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
        if (! Schema::hasTable(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)) {
            return null;
        }

        try {
            $snapshot = DB::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)
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
            return array_values(array_filter($value, is_array(...)));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = JsonCodec::decodeArray($value);

        return array_values(array_filter($decoded, is_array(...)));
    }
}
