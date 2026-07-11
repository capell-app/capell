<?php

declare(strict_types=1);

use Capell\Admin\Actions\Notifications\ListAdminNotificationSubscriptionStateAction;
use Capell\Admin\Actions\Notifications\ResolveAdminNotificationRecipientsAction;
use Capell\Admin\Actions\Notifications\SaveAdminNotificationSubscriptionsAction;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Capell\Core\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

it('defaults package operation notifications to super admins', function (): void {
    Role::findOrCreate('super_admin');
    Role::findOrCreate('editor');

    $superAdmin = UserFactory::new()->createOne()->assignRole('super_admin');
    $editor = UserFactory::new()->createOne()->assignRole('editor');

    $recipients = ResolveAdminNotificationRecipientsAction::run(AdminNotificationGroupEnum::PackageOperations);

    expect($recipients->pluck('id')->all())
        ->toContain($superAdmin->getKey())
        ->not->toContain($editor->getKey());
});

it('allows package notification groups to be registered outside the core enum', function (): void {
    $developer = UserFactory::new()->createOne();

    resolve(AdminNotificationGroupRegistry::class)->register(
        key: 'developer_exceptions',
        label: 'Developer exceptions',
        description: 'Unhandled exception reports for developers.',
        defaultRecipients: function () use ($developer): Collection {
            /** @var Collection<int, Model> $recipients */
            $recipients = new Collection([$developer]);

            return $recipients;
        },
    );

    $recipients = ResolveAdminNotificationRecipientsAction::run('developer_exceptions');

    expect($recipients->pluck('id')->all())->toBe([$developer->getKey()]);
});

it('applies explicit unsubscribe and subscribe overrides', function (): void {
    Role::findOrCreate('super_admin');

    $superAdmin = UserFactory::new()->createOne()->assignRole('super_admin');
    $developer = UserFactory::new()->createOne();

    SaveAdminNotificationSubscriptionsAction::run($superAdmin, []);
    SaveAdminNotificationSubscriptionsAction::run($developer, [AdminNotificationGroupEnum::PackageOperations->value]);

    $recipients = ResolveAdminNotificationRecipientsAction::run(AdminNotificationGroupEnum::PackageOperations);

    expect($recipients->pluck('id')->all())
        ->toContain($developer->getKey())
        ->not->toContain($superAdmin->getKey());
});

it('lists the subscription state that the user preference form should render', function (): void {
    Role::findOrCreate('super_admin');

    $superAdmin = UserFactory::new()->createOne()->assignRole('super_admin');
    $developer = UserFactory::new()->createOne();

    resolve(AdminNotificationGroupRegistry::class)->register(
        key: 'developer_exceptions',
        label: 'Developer exceptions',
        description: 'Unhandled exception reports for developers.',
        defaultRecipients: function () use ($developer): Collection {
            /** @var Collection<int, Model> $recipients */
            $recipients = new Collection([$developer]);

            return $recipients;
        },
    );

    expect(ListAdminNotificationSubscriptionStateAction::run($superAdmin))
        ->toBe([AdminNotificationGroupEnum::PackageOperations->value])
        ->and(ListAdminNotificationSubscriptionStateAction::run($developer))
        ->toBe(['developer_exceptions']);

    SaveAdminNotificationSubscriptionsAction::run($superAdmin, ['developer_exceptions']);
    SaveAdminNotificationSubscriptionsAction::run($developer, [
        AdminNotificationGroupEnum::PackageOperations->value,
        'missing_group',
    ]);

    expect(ListAdminNotificationSubscriptionStateAction::run($superAdmin))
        ->toBe(['developer_exceptions'])
        ->and(ListAdminNotificationSubscriptionStateAction::run($developer))
        ->toBe([AdminNotificationGroupEnum::PackageOperations->value]);
});
