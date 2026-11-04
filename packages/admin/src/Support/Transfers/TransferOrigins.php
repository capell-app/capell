<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Core\Support\TableName;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The panel each Filament export or import was started in, recorded when the record is created
 * so its download is later served by that panel. Until the table is migrated nothing is recorded,
 * and downloads belong to the default Capell panel as before.
 */
final class TransferOrigins
{
    private ?bool $available = null;

    /** Record the panel serving the request that created the record, if any. */
    public function record(Model $transfer): void
    {
        $download = TransferDownloadEnum::forModel($transfer);
        $panel = Filament::getCurrentPanel();
        if (! $download instanceof TransferDownloadEnum || ! $panel instanceof Panel || ! $this->isAvailable()) {
            return;
        }

        $now = now();
        $this->table()->upsert([[
            'transfer_type' => $download->value,
            'transfer_key' => (string) $transfer->getKey(),
            'panel' => $panel->getId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['transfer_type', 'transfer_key'], ['panel', 'updated_at']);
    }

    public function forget(Model $transfer): void
    {
        $download = TransferDownloadEnum::forModel($transfer);
        if ($download instanceof TransferDownloadEnum && $this->isAvailable()) {
            $this->table()->where('transfer_type', $download->value)->where('transfer_key', (string) $transfer->getKey())->delete();
        }
    }

    /** The id of the panel the record was started in, if one was recorded. */
    public function panelFor(TransferDownloadEnum $download, string $key): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $panel = $this->table()->where('transfer_type', $download->value)->where('transfer_key', $key)->value('panel');

        return is_string($panel) ? $panel : null;
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
