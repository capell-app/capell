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
                    ->formatStateUsing(static fn (InboundWebhookEventStatus $state): string => (string) __('capell-admin::inbound_webhooks.statuses.' . $state->value))
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
                        static fn (InboundWebhookEventStatus $status): array => [$status->value => (string) __('capell-admin::inbound_webhooks.statuses.' . $status->value)],
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

                $notification = Notification::make()->title((string) __('capell-admin::inbound_webhooks.replay_outcomes.' . $outcome->value));

                ($outcome === InboundWebhookReplayOutcome::Queued ? $notification->success() : $notification->warning())->send();
            });
    }
}
