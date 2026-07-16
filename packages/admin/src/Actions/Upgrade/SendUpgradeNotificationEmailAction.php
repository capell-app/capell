<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Admin\Notifications\UpgradeSummaryNotification;
use Illuminate\Support\Facades\Notification;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SendUpgradeNotificationEmailAction
{
    use AsFake;
    use AsObject;

    public function handle(): int
    {
        if (! $this->configBoolean('capell-admin.upgrades.notifications.enabled', true)) {
            return 0;
        }

        $recipientEmails = $this->recipientEmails();

        if ($recipientEmails === []) {
            return 0;
        }

        $summary = BuildUpgradeSummaryAction::run();

        if (! $summary->hasNotifications()) {
            return 0;
        }

        foreach ($recipientEmails as $recipientEmail) {
            Notification::route('mail', $recipientEmail)
                ->notify(new UpgradeSummaryNotification($summary));
        }

        return count($recipientEmails);
    }

    /**
     * @return array<int, string>
     */
    private function recipientEmails(): array
    {
        $configuredEmails = config('capell-admin.upgrades.notifications.emails', []);

        if (! is_array($configuredEmails)) {
            return [];
        }

        return collect($configuredEmails)
            ->filter(fn (mixed $configuredEmail): bool => is_string($configuredEmail)
                && filter_var($configuredEmail, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function configBoolean(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
