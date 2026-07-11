<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Core\Models\Blueprint;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

class BlueprintColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.blueprint'))
            ->icon(function (?Model $record): ?string {
                $relationName = str($this->getName())->before('.')->toString();
                $relationName = $relationName !== '' ? $relationName : 'blueprint';

                if (! $record?->relationLoaded($relationName)) {
                    return null;
                }

                $blueprint = $record->getRelation($relationName);

                return $blueprint instanceof Blueprint ? ($blueprint->admin['icon'] ?? null) : null;
            })
            ->sortable()
            ->color(FilamentColorEnum::LightGray->value)
            ->limit(30)
            ->grow(false)
            ->toggleable();
    }
}
