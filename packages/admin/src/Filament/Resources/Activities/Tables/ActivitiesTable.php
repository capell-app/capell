<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Activities\Tables;

use Capell\Admin\Actions\Activity\BuildActivityChangeSetAction;
use Capell\Admin\Actions\Activity\DeleteActivityLogAction;
use Capell\Admin\Actions\Activity\RevertActivityAction;
use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Support\Activity\ActivityChangeDetailsPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

final class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->label(__('capell-admin::dashboard.activity_change'))
                    ->formatStateUsing(fn (Activity $record): string => self::changeSet($record)->summary)
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label(__('capell-admin::activity.subject'))
                    ->formatStateUsing(fn (Activity $record): string => self::resourceLabel($record))
                    ->url(fn (Activity $record): ?string => self::changeSet($record)->resource?->url)
                    ->openUrlInNewTab()
                    ->placeholder(__('capell-admin::activity.subject_missing')),
                TextColumn::make('causer.name')
                    ->label(__('capell-admin::dashboard.activity_actor'))
                    ->placeholder(__('capell-admin::dashboard.activity_system'))
                    ->searchable(),
                TextColumn::make('event')
                    ->label(__('capell-admin::activity.event'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('capell-admin::table.created_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('capell-admin::activity.event'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('event')
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),
            ])
            ->recordActions([
                self::viewDetailsAction(),
                self::deleteActivityAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('capell-admin::activity.no_activities'))
            ->emptyStateDescription(__('capell-admin::activity.no_activities_description'))
            ->emptyStateIcon('heroicon-o-clock');
    }

    public static function viewDetailsAction(): Action
    {
        /** @var view-string $activityDetailsView */
        $activityDetailsView = 'capell-admin::activity.details';

        return Action::make('viewActivity')
            ->label(__('capell-admin::button.view'))
            ->icon('heroicon-o-eye')
            ->modalHeading(__('capell-admin::activity.activity_details'))
            ->modalWidth(Width::ScreenLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-admin::button.close'))
            ->modalContent(function (Activity $record) use ($activityDetailsView): HtmlString {
                $changeSet = self::changeSet($record);

                return new HtmlString(view($activityDetailsView, [
                    'activity' => $record,
                    'changeSet' => $changeSet,
                    'fieldDiffs' => resolve(ActivityChangeDetailsPresenter::class)->fields($changeSet),
                ])->render());
            })
            ->modalFooterActions(fn (Activity $record): array => self::modalFooterActions($record));
    }

    public static function deleteActivityAction(): Action
    {
        return Action::make('deleteActivity')
            ->label(__('capell-admin::button.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::activity.delete_heading'))
            ->modalDescription(__('capell-admin::activity.delete_description'))
            ->visible(fn (): bool => self::canDeleteActivity())
            ->action(function (Activity $record): void {
                DeleteActivityLogAction::run($record);

                Notification::make('activity-deleted')
                    ->title(__('capell-admin::activity.deleted'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Action>
     */
    private static function modalFooterActions(Activity $record): array
    {
        $changeSet = self::changeSet($record);
        $actions = [];

        if ($changeSet->resource?->url !== null) {
            $actions[] = Action::make('openSubject')
                ->label(__('capell-admin::activity.open_resource'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url($changeSet->resource->url, shouldOpenInNewTab: true);
        }

        if (self::canRevertActivity() && self::revertableFieldPaths($changeSet) !== []) {
            $actions[] = Action::make('revertActivity')
                ->label(__('capell-admin::activity.revert_selected'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('capell-admin::activity.revert_heading'))
                ->modalDescription(__('capell-admin::activity.revert_description'))
                ->schema([
                    CheckboxList::make('selectedPaths')
                        ->label(__('capell-admin::activity.revert_fields'))
                        ->helperText(__('capell-admin::activity.revert_fields_help'))
                        ->options(self::revertableFieldOptions($changeSet))
                        ->required()
                        ->columns(1),
                ])
                ->action(function (array $data) use ($record): void {
                    $result = RevertActivityAction::run($record, self::selectedPaths($data));
                    $notification = Notification::make('activity-reverted')
                        ->title(__($result->messageKey));

                    $skippedFieldsSummary = self::skippedFieldsSummary($result);

                    if ($skippedFieldsSummary !== null) {
                        $notification->body($skippedFieldsSummary);
                    }

                    if ($result->successful) {
                        $notification->success();
                    } else {
                        $notification->danger();
                    }

                    $notification->send();
                });
        }

        return $actions;
    }

    private static function changeSet(Activity $activity): ActivityChangeSetData
    {
        $relation = 'capellActivityChangeSet';
        $cachedChangeSet = $activity->relationLoaded($relation) ? $activity->getRelation($relation) : null;

        if ($cachedChangeSet instanceof ActivityChangeSetData) {
            return $cachedChangeSet;
        }

        $cacheKey = 'capell.activity_change_sets';
        $activityKey = self::activityCacheKey($activity);
        $cachedChangeSets = request()->attributes->get($cacheKey, []);
        $requestCachedChangeSet = is_array($cachedChangeSets) ? ($cachedChangeSets[$activityKey] ?? null) : null;

        if ($requestCachedChangeSet instanceof ActivityChangeSetData) {
            $activity->setRelation($relation, $requestCachedChangeSet);

            return $requestCachedChangeSet;
        }

        $changeSet = BuildActivityChangeSetAction::run($activity);
        $activity->setRelation($relation, $changeSet);
        $cachedChangeSets = is_array($cachedChangeSets) ? $cachedChangeSets : [];
        $cachedChangeSets[$activityKey] = $changeSet;
        request()->attributes->set($cacheKey, $cachedChangeSets);

        return $changeSet;
    }

    private static function activityCacheKey(Activity $activity): string
    {
        $activityKey = $activity->getKey();

        if ($activityKey === null) {
            return 'unsaved:' . spl_object_id($activity);
        }

        return $activity::class . ':' . $activityKey;
    }

    private static function resourceLabel(Activity $activity): string
    {
        return self::changeSet($activity)->resource->label
            ?? __('capell-admin::activity.subject_missing');
    }

    private static function canRevertActivity(): bool
    {
        return auth()->user()?->can(CapellPermission::RevertActivityLog->name()) === true;
    }

    private static function canDeleteActivity(): bool
    {
        return auth()->user()?->can(CapellPermission::DeleteActivityLog->name()) === true;
    }

    /**
     * @return array<string, string>
     */
    private static function revertableFieldOptions(ActivityChangeSetData $changeSet): array
    {
        $options = [];

        foreach ($changeSet->fields as $field) {
            if (! $field->reversible) {
                continue;
            }

            $options[$field->path] = self::fieldLabel($field);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function revertableFieldPaths(ActivityChangeSetData $changeSet): array
    {
        return array_keys(self::revertableFieldOptions($changeSet));
    }

    private static function fieldLabel(ActivityChangedFieldData $field): string
    {
        return filled($field->label) ? $field->label : $field->path;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function selectedPaths(array $data): array
    {
        $selectedPaths = $data['selectedPaths'] ?? [];

        if (! is_array($selectedPaths)) {
            return [];
        }

        return array_values(array_filter(
            $selectedPaths,
            fn (mixed $selectedPath): bool => is_string($selectedPath) && $selectedPath !== '',
        ));
    }

    private static function skippedFieldsSummary(ActivityRevertResultData $result): ?string
    {
        $groups = [];

        foreach ($result->skippedFields as $reason => $fields) {
            if ($fields === []) {
                continue;
            }

            $groups[] = __('capell-admin::activity.skipped_fields_group', [
                'reason' => __('capell-admin::activity.skip_reasons.' . $reason),
                'fields' => implode(', ', $fields),
            ]);
        }

        if ($groups === []) {
            return null;
        }

        return __('capell-admin::activity.skipped_fields_summary', [
            'groups' => implode("\n", $groups),
        ]);
    }
}
