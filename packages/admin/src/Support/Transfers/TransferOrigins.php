<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Core\Support\TableName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The panel each Filament export or import was started in, and the host that served it, recorded
 * when the record is created so its download is later served by that panel alone. A record with no
 * origin, including every record created before the table was migrated, is never downloaded.
 */
final class TransferOrigins
{
    private ?bool $available = null;

    /**
     * Record the panel whose route served the request that created the record (on a Livewire update, the route
     * the update replays), with the request's host and port. A record created without a panel route is not
     * recorded, so its download is refused.
     */
    public function record(Model $transfer): void
    {
        $download = TransferDownloadEnum::forModel($transfer);
        $panel = resolve(InstalledPanelRuntime::class)->servingPanel(request());
        if (! $download instanceof TransferDownloadEnum || $panel === null || ! $this->isAvailable()) {
            return;
        }

        $now = now();
        $this->table()->upsert([[
            'transfer_type' => $download->value,
            'transfer_key' => (string) $transfer->getKey(),
            'panel' => $panel,
            'host' => request()->getHttpHost(),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['transfer_type', 'transfer_key'], ['panel', 'host', 'updated_at']);
    }

    public function forget(Model $transfer): void
    {
        $download = TransferDownloadEnum::forModel($transfer);
        if ($download instanceof TransferDownloadEnum && $this->isAvailable()) {
            $this->table()->where('transfer_type', $download->value)->where('transfer_key', (string) $transfer->getKey())->delete();
        }
    }

    /** Where the record was started, if that was recorded. */
    public function originFor(TransferDownloadEnum $download, string $key): ?TransferOrigin
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $row = $this->table()->where('transfer_type', $download->value)->where('transfer_key', $key)->first(['panel', 'host']);
        if ($row === null || ! is_string($row->panel) || ! is_string($row->host) || $row->host === '') {
            return null;
        }

        return new TransferOrigin($row->panel, $row->host);
    }

    private function isAvailable(): bool
    {
        return $this->available ??= Schema::hasTable(TableName::resolve('admin_transfer_origins'));
    }

    private function table(): Builder
    {
        return DB::table(TableName::resolve('admin_transfer_origins'));
    }
}
