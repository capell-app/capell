<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\InboundWebhookEvents\Tables;

use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Core\Actions\Webhooks\ReplayInboundWebhookEventAction;
use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Enums\InboundWebhookReplayOutcome;
use Capell\Core\Models\InboundWebhookEvent;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class InboundWebhookEventsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('capell-admin::inbound_webhooks.provider'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('capell-admin::inbound_webhooks.type'))
                    ->searchable(),
                TextColumn::make('subject')
                    ->label(__('capell-admin::inbound_webhooks.subject'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('capell-admin::inbound_webhooks.status'))
                    ->badge()
                    ->formatStateUsing(static fn (InboundWebhookEventStatus $state): string => self::statusLabel($state))
                    ->color(static fn (InboundWebhookEventStatus $state): string => match ($state) {
                        InboundWebhookEventStatus::Processed => 'success',
                        InboundWebhookEventStatus::Failed => 'danger',
                        InboundWebhookEventStatus::Ignored => 'gray',
                        InboundWebhookEventStatus::Received, InboundWebhookEventStatus::Processing => 'warning',
                    }),
                TextColumn::make('attempts')
                    ->label(__('capell-admin::inbound_webhooks.attempts'))
                    ->numeric(),
                TextColumn::make('last_error')
                    ->label(__('capell-admin::inbound_webhooks.last_error'))
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('occurred_at')
                    ->label(__('capell-admin::inbound_webhooks.occurred_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('processed_at')
                    ->label(__('capell-admin::inbound_webhooks.processed_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('capell-admin::inbound_webhooks.status'))
                    ->options(collect(InboundWebhookEventStatus::cases())->mapWithKeys(
                        static fn (InboundWebhookEventStatus $status): array => [$status->value => self::statusLabel($status)],
                    )->all()),
                SelectFilter::make('provider')
                    ->label(__('capell-admin::inbound_webhooks.provider'))
                    ->options(static fn (): array => InboundWebhookEvent::query()->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
            ])
            ->recordActions([self::replayAction()])
            ->emptyStateHeading(__('capell-admin::inbound_webhooks.empty_heading'))
            ->emptyStateDescription(__('capell-admin::inbound_webhooks.empty_description'));
    }

    private static function replayAction(): Action
    {
        return Action::make('replay')
            ->label(__('capell-admin::inbound_webhooks.replay'))
            ->icon('heroicon-o-arrow-path')
            ->authorize(static fn (InboundWebhookEvent $record): bool => auth()->user()?->can('update', $record) === true)
            ->visible(static fn (InboundWebhookEvent $record): bool => $record->status !== InboundWebhookEventStatus::Ignored && $record->handler !== null)
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::inbound_webhooks.replay_heading'))
            ->modalDescription(__('capell-admin::inbound_webhooks.replay_description'))
            ->schema(static fn (InboundWebhookEvent $record): array => $record->status === InboundWebhookEventStatus::Processed ? [
                Toggle::make('force')
                    ->label(__('capell-admin::inbound_webhooks.replay_force'))
                    ->helperText(__('capell-admin::inbound_webhooks.replay_force_help'))
                    ->accepted(),
            ] : [])
            ->action(static function (InboundWebhookEvent $record, array $data): void {
                $outcome = ReplayInboundWebhookEventAction::run($record, ($data['force'] ?? false) === true);

                $notification = Notification::make()->title(self::outcomeLabel($outcome));

                ($outcome === InboundWebhookReplayOutcome::Queued ? $notification->success() : $notification->warning())->send();
            });
    }

    private static function statusLabel(InboundWebhookEventStatus $status): string
    {
        return (string) match ($status) {
            InboundWebhookEventStatus::Received => __('capell-admin::inbound_webhooks.statuses.received'),
            InboundWebhookEventStatus::Processing => __('capell-admin::inbound_webhooks.statuses.processing'),
            InboundWebhookEventStatus::Processed => __('capell-admin::inbound_webhooks.statuses.processed'),
            InboundWebhookEventStatus::Ignored => __('capell-admin::inbound_webhooks.statuses.ignored'),
            InboundWebhookEventStatus::Failed => __('capell-admin::inbound_webhooks.statuses.failed'),
        };
    }

    private static function outcomeLabel(InboundWebhookReplayOutcome $outcome): string
    {
        return (string) match ($outcome) {
            InboundWebhookReplayOutcome::Queued => __('capell-admin::inbound_webhooks.replay_outcomes.queued'),
            InboundWebhookReplayOutcome::Ignored => __('capell-admin::inbound_webhooks.replay_outcomes.ignored'),
            InboundWebhookReplayOutcome::AlreadyProcessed => __('capell-admin::inbound_webhooks.replay_outcomes.already-processed'),
            InboundWebhookReplayOutcome::AlreadyQueued => __('capell-admin::inbound_webhooks.replay_outcomes.already-queued'),
        };
    }
}
