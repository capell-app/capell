<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\RelationManagers;

use BackedEnum;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\RollbackDirection;
use Capell\Admin\Support\Activity\ActivityChangeDetailsPresenter;
use Capell\Admin\Support\EventSourcing\RollbackChangeSetMapper;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Exceptions\RollbackBlocked;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\Actions\BuildRollbackPreviewAction;
use Capell\Core\EventSourcing\Rollback\RollbackIssueData;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\PageRevision;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Generic event-sourcing history timeline + bidirectional restore. Attaches to
 * any model that uses IsEventSourced via the `pageRevisions` relation (the
 * foundation's payoff: adding history + restore to a resource is ~one line).
 *
 * The live content corresponds to an "active content version" (the head event,
 * or — when the head is a rollback — the version that rollback restored). Each
 * row is framed against it: older rows offer "Roll back" (undo), newer rows
 * offer "Roll forward" (redo of undone content), and the head row is marked
 * "Current" with no action. Every restore runs the same append-only engine; the
 * modal renders the diff through the existing activity-diff presenter and
 * surfaces validation issues. Gated by the can-rollback-page permission.
 */
final class EventSourcedHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'pageRevisions';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof EventSourced;
    }

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::event-sourcing.history_title');
    }

    #[Override]
    public function table(Table $table): Table
    {
        $headVersion = $this->headVersion();
        $activeContentVersion = $this->activeContentVersion();

        return $table
            ->recordTitleAttribute('summary')
            // Eager-load the actor so the "Changed by" column does not N+1.
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->columns([
                TextColumn::make('version')
                    ->label(__('capell-admin::event-sourcing.version'))
                    ->sortable(),
                TextColumn::make('summary')
                    ->label(__('capell-admin::event-sourcing.summary'))
                    // Render a translated label from the structured columns
                    // rather than the English string the core projector bakes
                    // into `summary`, so non-English admins see localised text.
                    ->state(fn (PageRevision $record): string => $record->is_rollback
                        ? __('capell-admin::event-sourcing.rollback_summary', ['version' => $record->version])
                        : __('capell-admin::event-sourcing.change_revision'))
                    ->wrap(),
                TextColumn::make('actor.name')
                    ->label(__('capell-admin::event-sourcing.changed_by'))
                    // Null actor = console / unauthenticated save.
                    ->placeholder(__('capell-admin::event-sourcing.system_actor'))
                    ->tooltip(fn (PageRevision $record): ?string => $record->actor === null
                        ? (string) __('capell-admin::event-sourcing.system_actor_help')
                        : null),
                // Edit vs Restore, at a glance — clearer than a bare checkmark.
                TextColumn::make('type')
                    ->label(__('capell-admin::event-sourcing.type'))
                    ->state(fn (PageRevision $record): string => $record->is_rollback
                        ? (string) __('capell-admin::event-sourcing.type_restore')
                        : (string) __('capell-admin::event-sourcing.type_edit'))
                    ->badge()
                    ->color(fn (PageRevision $record): string => $record->is_rollback ? 'info' : 'gray'),
                TextColumn::make('occurred_at')
                    ->label(__('capell-admin::event-sourcing.occurred_at'))
                    // Editors think "yesterday", not timestamps; exact time on hover.
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                // Marks the live row (latest event). Empty on every other row.
                TextColumn::make('current')
                    ->label('')
                    ->state(fn (PageRevision $record): ?string => $record->version === $headVersion
                        ? (string) __('capell-admin::event-sourcing.rollback_current')
                        : null)
                    ->badge()
                    ->icon(fn (PageRevision $record): ?Heroicon => $record->version === $headVersion
                        ? Heroicon::CheckCircle
                        : null)
                    ->color('success'),
            ])
            ->defaultSort('version', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->emptyStateHeading(__('capell-admin::event-sourcing.empty_heading'))
            ->emptyStateDescription(__('capell-admin::event-sourcing.empty_description'))
            ->recordActions([
                $this->rollbackAction($headVersion, $activeContentVersion),
            ]);
    }

    /**
     * The latest event version — the row that represents the live state and so
     * carries the "Current" badge with no restore action.
     */
    private function headVersion(): int
    {
        $page = $this->getOwnerRecord();

        if (! $page instanceof EventSourced) {
            return 0;
        }

        return resolve(RollbackService::class)->currentVersion($page->aggregateUuid());
    }

    /**
     * The version whose content is live right now (head, or the rollback origin
     * when the head event is itself a rollback). Drives Back vs Forward framing.
     */
    private function activeContentVersion(): int
    {
        $page = $this->getOwnerRecord();

        if (! $page instanceof EventSourced) {
            return 0;
        }

        return resolve(RollbackService::class)->activeContentVersion($page->aggregateUuid());
    }

    private function directionFor(PageRevision $record, int $activeContentVersion): RollbackDirection
    {
        return RollbackDirection::forVersions($record->version, $activeContentVersion);
    }

    private function rollbackAction(int $headVersion, int $activeContentVersion): Action
    {
        return Action::make('rollback')
            ->label(fn (PageRevision $record): string => __(
                $this->directionFor($record, $activeContentVersion)->actionLabelKey(),
            ))
            ->icon(fn (PageRevision $record): Heroicon => $this->directionFor($record, $activeContentVersion)->icon())
            ->color(fn (PageRevision $record): string => $this->directionFor($record, $activeContentVersion)->color())
            // Hidden on the live rows (the head row and the active-content row,
            // which differ only when the head event is a rollback) and when the
            // user lacks permission.
            ->visible(fn (PageRevision $record): bool => $record->version !== $headVersion
                && $this->directionFor($record, $activeContentVersion) !== RollbackDirection::Current
                && auth()->user()?->can(CapellPermission::RollbackPage->name()) === true)
            // Server-side guard: visibility alone hides the button but does not
            // stop a forged action request from executing the restore.
            ->authorize(static fn (): bool => auth()->user()?->can(CapellPermission::RollbackPage->name()) === true)
            ->modalHeading(fn (PageRevision $record): string => __(
                $this->directionFor($record, $activeContentVersion)->headingKey(),
                ['version' => $record->version],
            ))
            ->modalSubmitActionLabel(fn (PageRevision $record): string => __(
                $this->directionFor($record, $activeContentVersion)->confirmKey(),
            ))
            // The crucial reassurance: restoring is append-only and reversible.
            ->modalDescription(fn (PageRevision $record): string => __(
                $this->directionFor($record, $activeContentVersion)->helpKey(),
                ['version' => $record->version],
            ))
            ->modalContent(function (PageRevision $record) use ($activeContentVersion): ?HtmlString {
                $page = $this->getOwnerRecord();

                if (! $page instanceof EventSourced) {
                    return null;
                }

                $direction = $this->directionFor($record, $activeContentVersion);
                $preview = BuildRollbackPreviewAction::run($page, $record->version);

                $changeSet = resolve(RollbackChangeSetMapper::class)->toChangeSet(
                    $preview,
                    __($direction->summaryKey(), ['version' => $record->version]),
                    auth()->user()->name ?? '',
                );

                $fieldDiffs = resolve(ActivityChangeDetailsPresenter::class)->fields($changeSet);

                return new HtmlString(view('capell-admin::event-sourcing.rollback-preview', [
                    'preview' => $preview,
                    'fieldDiffs' => $fieldDiffs,
                    'direction' => $direction,
                    'targetVersion' => $record->version,
                    'targetActor' => $record->actor instanceof Model
                        ? (string) $record->actor->getAttribute('name')
                        : null,
                    'targetDate' => $record->occurred_at,
                ])->render());
            })
            ->action(function (PageRevision $record) use ($activeContentVersion): void {
                $page = $this->getOwnerRecord();

                if (! $page instanceof EventSourced) {
                    return;
                }

                // Re-check before applying so a blocked or no-op restore never
                // records a pointless PageRolledBack event — the modal may have
                // been open while the page changed underneath it.
                $preview = BuildRollbackPreviewAction::run($page, $record->version);

                if ($preview->isBlocked()) {
                    Notification::make()
                        ->danger()
                        ->title(__('capell-admin::event-sourcing.rollback_blocked'))
                        ->body(implode(' ', array_map(
                            static fn ($issue): string => $issue->message,
                            $preview->blockingIssues(),
                        )))
                        ->send();

                    return;
                }

                if (! $preview->hasChanges()) {
                    Notification::make()
                        ->warning()
                        ->title(__('capell-admin::event-sourcing.rollback_no_changes'))
                        ->send();

                    return;
                }

                try {
                    ApplyRollbackAction::run($page, $record->version);

                    Notification::make()
                        ->success()
                        ->title(__($this->directionFor($record, $activeContentVersion)->doneKey()))
                        ->send();
                } catch (RollbackBlocked $rollbackBlocked) {
                    Notification::make()
                        ->danger()
                        ->title(__('capell-admin::event-sourcing.rollback_blocked'))
                        ->body(implode(' ', array_map(
                            static fn (RollbackIssueData $issue): string => $issue->message,
                            $rollbackBlocked->issues,
                        )))
                        ->send();
                }
            });
    }
}
