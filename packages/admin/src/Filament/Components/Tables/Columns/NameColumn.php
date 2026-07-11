<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Model;

class NameColumn extends BadgeableColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.name'))
            ->weight(FontWeight::Medium)
            ->sortable()
            ->searchable()
            ->icon(function (Model $record): string {
                $type = $this->resolveTypeRecord($record);

                return $type?->admin['icon'] ?? '';
            });
    }

    private function resolveTypeRecord(Model $record): ?Blueprint
    {
        if ($record instanceof Blueprint) {
            return $record;
        }

        if (! $record instanceof Blueprintable) {
            return null;
        }

        $record->loadMissing('blueprint');

        return $record->getRelation('type');
    }
}
