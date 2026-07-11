<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Actions\Notifications\ListAdminNotificationSubscriptionStateAction;
use Capell\Admin\Actions\Notifications\SaveAdminNotificationSubscriptionsAction;
use Capell\Admin\Data\Notifications\AdminNotificationGroupData;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Override;

final class AdminNotificationPreferencesUserResourceBridge extends AbstractUserResourceBridge
{
    #[Override]
    public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        if ($hook !== UserSchemaHookEnum::AfterRoles || ! $context->record instanceof Model) {
            return [];
        }

        if (! SchemaFacade::hasTable('capell_admin_notification_subscriptions')) {
            return [];
        }

        $registry = resolve(AdminNotificationGroupRegistry::class);

        if ($registry->all() === []) {
            return [];
        }

        return [
            Section::make(__('capell-admin::notification_preferences.heading'))
                ->description(__('capell-admin::notification_preferences.description'))
                ->schema([
                    CheckboxList::make('admin_notification_subscriptions')
                        ->label(__('capell-admin::notification_preferences.groups'))
                        ->options(fn (): array => $registry->options())
                        ->descriptions(fn (): array => collect($registry->all())
                            ->mapWithKeys(static fn (AdminNotificationGroupData $group): array => [$group->key => $group->description])
                            ->all())
                        ->default(fn (): array => ListAdminNotificationSubscriptionStateAction::run($context->record))
                        ->columns()
                        ->dehydrated(),
                ])
                ->columnSpanFull(),
        ];
    }

    #[Override]
    public function mutateDataBeforeSave(Model $record, array $data): array
    {
        if (! array_key_exists('admin_notification_subscriptions', $data)) {
            return $data;
        }

        $notificationSubscriptions = is_array($data['admin_notification_subscriptions'])
            ? array_values($data['admin_notification_subscriptions'])
            : [];

        SaveAdminNotificationSubscriptionsAction::run($record, $notificationSubscriptions);

        unset($data['admin_notification_subscriptions']);

        return $data;
    }
}
