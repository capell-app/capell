<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Activities;

use BackedEnum;
use Capell\Admin\Contracts\DashboardReports\ActivityTrailQueryProvider;
use Capell\Admin\Filament\Resources\Activities\Pages\ListActivities;
use Capell\Admin\Filament\Resources\Activities\Tables\ActivitiesTable;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Override;
use Spatie\Activitylog\Models\Activity;

final class ActivityResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Clock;

    protected static ?int $navigationSort = 20;

    /**
     * @return class-string<Activity>
     */
    #[Override]
    public static function getModel(): string
    {
        return Activity::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.activity_trail');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_workflow');
    }

    #[Override]
    public static function getNavigationBadge(): ?string
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable((new Activity)->getTable())) {
            return null;
        }

        $count = Activity::query()
            ->whereNotNull('properties->workspace_id')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    #[Override]
    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('capell-admin::activity.activities');
    }

    #[Override]
    public static function canAccess(): bool
    {
        return resolve(RuntimeSchemaState::class)->hasTable((new Activity)->getTable()) && parent::canAccess();
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return __('capell-admin::activity.activity');
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return resolve(ActivityTrailQueryProvider::class)
            ->build()
            ->with('causer')
            ->with([
                'subject' => function (Relation $relation): Relation {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith([
                            Translation::class => [
                                'language',
                                'translatable.type',
                            ],
                        ]);
                    }

                    return $relation;
                },
            ])
            ->latest('created_at');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
