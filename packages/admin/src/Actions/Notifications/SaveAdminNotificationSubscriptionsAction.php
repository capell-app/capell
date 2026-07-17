<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Notifications;

use Capell\Admin\Models\AdminNotificationSubscription;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SaveAdminNotificationSubscriptionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $subscribedGroupKeys
     */
    public function handle(Model $user, array $subscribedGroupKeys): void
    {
        if (! Schema::hasTable('capell_admin_notification_subscriptions')) {
            return;
        }

        $knownGroupKeys = array_keys(resolve(AdminNotificationGroupRegistry::class)->all());
        $subscribedGroupKeys = collect($subscribedGroupKeys)
            ->intersect($knownGroupKeys)
            ->values()
            ->all();

        foreach ($knownGroupKeys as $groupKey) {
            AdminNotificationSubscription::query()->updateOrCreate(
                [
                    'user_type' => $user::class,
                    'user_id' => $user->getKey(),
                    'group_key' => $groupKey,
                ],
                [
                    'subscribed' => in_array($groupKey, $subscribedGroupKeys, true),
                ],
            );
        }
    }
}
