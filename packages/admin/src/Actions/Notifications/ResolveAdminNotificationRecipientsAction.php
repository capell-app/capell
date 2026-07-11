<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Notifications;

use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Admin\Models\AdminNotificationSubscription;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveAdminNotificationRecipientsAction
{
    use AsAction;

    /**
     * @return Collection<int, Model>
     */
    public function handle(AdminNotificationGroupEnum|string $group): Collection
    {
        $groupKey = $group instanceof AdminNotificationGroupEnum ? $group->value : $group;
        $definition = resolve(AdminNotificationGroupRegistry::class)->get($groupKey);

        if ($definition === null) {
            return collect();
        }

        $recipients = ($definition->defaultRecipients)()
            ->filter(static fn (Model $recipient): bool => filled($recipient->getKey()))
            ->keyBy(static fn (Model $recipient): string => $recipient::class . ':' . $recipient->getKey());

        if (! Schema::hasTable('capell_admin_notification_subscriptions')) {
            return $recipients->values();
        }

        AdminNotificationSubscription::query()
            ->with('user')
            ->where('group_key', $groupKey)
            ->get()
            ->each(function (AdminNotificationSubscription $subscription) use ($recipients): void {
                $user = $subscription->user;

                if (! $user instanceof Model || blank($user->getKey())) {
                    return;
                }

                $recipientKey = $user::class . ':' . $user->getKey();

                if ($subscription->subscribed) {
                    $recipients->put($recipientKey, $user);

                    return;
                }

                $recipients->forget($recipientKey);
            });

        return $recipients->values();
    }
}
