<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Actions\Notifications\ResolveAdminNotificationRecipientsAction;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Notifications\MarketplaceInstallOperationFailedNotification;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class NotifyMarketplaceInstallOperationFailureAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt, ?string $reason = null): void
    {
        $resolvedReason = $reason ?? $attempt->failure_reason ?? (string) __('capell-marketplace::marketplace.operations.notification_unknown_reason');
        $recipients = ResolveAdminNotificationRecipientsAction::run(AdminNotificationGroupEnum::PackageOperations);
        $this->sendFilamentNotifications($attempt, $resolvedReason, $recipients);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send(
                $recipients,
                new MarketplaceInstallOperationFailedNotification(
                    attempt: $attempt,
                    reason: $resolvedReason,
                ),
            );
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * @param  Collection<int, Model>  $recipients
     */
    private function sendFilamentNotifications(MarketplaceInstallAttempt $attempt, string $reason, Collection $recipients): void
    {
        foreach ($this->filamentRecipients($attempt, $recipients) as $recipient) {
            $notification = FilamentNotification::make(MarketplaceInstallNotifications::operationId($attempt->composer_name))
                ->title((string) __('capell-marketplace::marketplace.operations.notification_subject', [
                    'package' => $attempt->composer_name,
                ]))
                ->body($reason)
                ->danger()
                ->persistent()
                ->actions([
                    Action::make('viewMarketplaceInstallOperation')
                        ->label((string) __('capell-marketplace::marketplace.install.check_operation'))
                        ->icon(Heroicon::OutlinedQueueList)
                        ->link()
                        ->close()
                        ->url(MarketplacePackageOperationsPage::getUrl([
                            'operation' => $attempt->getKey(),
                            'tab' => 'failed',
                        ])),
                ]);

            try {
                $notification->broadcast($recipient);

                if (Schema::hasTable('notifications')) {
                    $notification->sendToDatabase($recipient);
                }
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
    }

    /**
     * @param  Collection<int, Model>  $recipients
     * @return array<int, Authenticatable&Model>
     */
    private function filamentRecipients(MarketplaceInstallAttempt $attempt, Collection $recipients): array
    {
        $resolvedRecipients = $recipients
            ->filter(fn (Model $recipient): bool => $recipient instanceof Authenticatable)
            ->keyBy(fn (Model $recipient): string => $recipient::class . ':' . $recipient->getKey());

        $requestingUser = $this->requestingUser($attempt);

        if ($requestingUser instanceof Model && $requestingUser instanceof Authenticatable) {
            $resolvedRecipients->put($requestingUser::class . ':' . $requestingUser->getKey(), $requestingUser);
        }

        return $resolvedRecipients->values()->all();
    }

    private function requestingUser(MarketplaceInstallAttempt $attempt): Model|Authenticatable|null
    {
        if ($attempt->user_id === null || $attempt->user_id === '') {
            return null;
        }

        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! class_exists($userModel)) {
            return null;
        }

        $user = new $userModel;

        if (! $user instanceof Model) {
            return null;
        }

        $foundUser = $user->newQuery()->whereKey($attempt->user_id)->first();

        return $foundUser instanceof Model || $foundUser instanceof Authenticatable
            ? $foundUser
            : null;
    }
}
