<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Notifications;

use Capell\Admin\Models\AdminNotificationSubscription;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListAdminNotificationSubscriptionStateAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<int, string>
     */
    public function handle(Model $user): array
    {
        $groups = resolve(AdminNotificationGroupRegistry::class)->all();

        if (! Schema::hasTable('capell_admin_notification_subscriptions')) {
            return $this->defaultSubscribedGroups($groups, $user);
        }

        $overrides = AdminNotificationSubscription::query()
            ->where('user_type', $user::class)
            ->where('user_id', $user->getKey())
            ->pluck('subscribed', 'group_key');

        return collect($groups)
            ->filter(function (mixed $definition, string $groupKey) use ($overrides, $user): bool {
                if ($overrides->has($groupKey)) {
                    return (bool) $overrides->get($groupKey);
                }

                return ResolveAdminNotificationRecipientsAction::run($groupKey)
                    ->contains(static fn (Model $recipient): bool => $recipient::class === $user::class
                        && (string) $recipient->getKey() === (string) $user->getKey());
            })
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $groups
     * @return array<int, string>
     */
    private function defaultSubscribedGroups(array $groups, Model $user): array
    {
        return collect($groups)
            ->filter(fn (mixed $definition, string $groupKey): bool => ResolveAdminNotificationRecipientsAction::run($groupKey)
                ->contains(static fn (Model $recipient): bool => $recipient::class === $user::class
                    && (string) $recipient->getKey() === (string) $user->getKey()))
            ->keys()
            ->values()
            ->all();
    }
}
