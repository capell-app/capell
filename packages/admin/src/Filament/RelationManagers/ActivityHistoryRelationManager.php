<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\RelationManagers;

use BackedEnum;
use Capell\Admin\Filament\Resources\Activities\Tables\ActivitiesTable;
use Capell\Core\Models\Translation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Override;

final class ActivityHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::tab.history');
    }

    #[Override]
    public function table(Table $table): Table
    {
        return ActivitiesTable::configure($table)
            ->heading(__('capell-admin::tab.history'))
            ->description(__('capell-admin::activity.resource_history_description'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('causer')
                ->with([
                    'subject' => function (Relation $relation): Relation {
                        if ($relation instanceof MorphTo) {
                            $relation->morphWith([
                                Translation::class => [
                                    'language',
                                    'translatable.blueprint',
                                ],
                            ]);
                        }

                        return $relation;
                    },
                ]));
    }
}
