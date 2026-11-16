<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\InboundWebhookEvents;

use BackedEnum;
use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Filament\Resources\InboundWebhookEvents\Pages\ListInboundWebhookEvents;
use Capell\Admin\Filament\Resources\InboundWebhookEvents\Tables\InboundWebhookEventsTable;
use Capell\Core\Models\InboundWebhookEvent;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Webhook deliveries Core's receiver stored, across every provider. Operators see each event's
 * provider, type, subject, status and failure, and replay one after fixing its cause. Payloads are
 * encrypted personal data and are never shown.
 */
final class InboundWebhookEventResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::InboxArrowDown;

    protected static ?int $navigationSort = 40;

    /** @return class-string<InboundWebhookEvent> */
    #[Override]
    public static function getModel(): string
    {
        return InboundWebhookEvent::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.inbound_webhook_events');
    }

    #[Override]
    public static function getNavigationGroup(): NavigationGroupEnum
    {
        return NavigationGroupEnum::Settings;
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return (string) __('capell-admin::model_labels.inbound_webhook_event');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return (string) __('capell-admin::model_labels.inbound_webhook_events');
    }

    #[Override]
    public static function canAccess(): bool
    {
        return resolve(RuntimeSchemaState::class)->hasTable((new InboundWebhookEvent)->getTable()) && parent::canAccess();
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return InboundWebhookEventsTable::configure($table);
    }

    /** @return Builder<InboundWebhookEvent> */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return InboundWebhookEvent::query()->latest('id');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInboundWebhookEvents::route('/'),
        ];
    }
}
