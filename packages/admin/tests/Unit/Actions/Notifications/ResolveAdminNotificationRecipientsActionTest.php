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

    // A group that accepts any subscriber, as groups registered without an eligibility check do.
    resolve(AdminNotificationGroupRegistry::class)->register(
        key: 'open_group',
        label: 'Open group',
        description: 'Anyone may subscribe.',
        defaultRecipients: function () use ($superAdmin): Collection {
            /** @var Collection<int, Model> $recipients */
            $recipients = new Collection([$superAdmin]);

            return $recipients;
        },
    );

    SaveAdminNotificationSubscriptionsAction::run($superAdmin, []);
    SaveAdminNotificationSubscriptionsAction::run($developer, ['open_group']);

    $recipients = ResolveAdminNotificationRecipientsAction::run('open_group');

    expect($recipients->pluck('id')->all())
        ->toContain($developer->getKey())
        ->not->toContain($superAdmin->getKey());
});

it('does not send package operation notifications to a subscriber who is no longer allowed to receive them', function (): void {
    Role::findOrCreate('super_admin');

    $superAdmin = UserFactory::new()->createOne()->assignRole('super_admin');
    $demoted = UserFactory::new()->createOne()->assignRole('super_admin');
    $outsider = UserFactory::new()->createOne();

    SaveAdminNotificationSubscriptionsAction::run($demoted, [AdminNotificationGroupEnum::PackageOperations->value]);
    SaveAdminNotificationSubscriptionsAction::run($outsider, [AdminNotificationGroupEnum::PackageOperations->value]);

    $demoted->removeRole('super_admin');

    $recipients = ResolveAdminNotificationRecipientsAction::run(AdminNotificationGroupEnum::PackageOperations);

    expect($recipients->pluck('id')->all())
        ->toContain($superAdmin->getKey())
        ->not->toContain($demoted->getKey())
        ->not->toContain($outsider->getKey());
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
