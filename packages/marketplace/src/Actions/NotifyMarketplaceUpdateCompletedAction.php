<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Tell whoever asked for the update that it landed, and which version they now
 * have.
 *
 * Separate from the install notification rather than a flag on it: the install
 * notification's whole value is the "now go and use it" action it carries, and
 * an update has no such next step — the extension was already set up. What an
 * update's audience wants is the version number.
 */
final class NotifyMarketplaceUpdateCompletedAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string|null  $runtimeNotice  What could not be refreshed on this
     *                                      operator's behalf. Carried in the body rather than left to the
     *                                      timeline, because an update they believe is live everywhere is
     *                                      exactly the belief this corrects.
     */
    public function handle(MarketplaceInstallAttempt $attempt, ?string $runtimeNotice = null): void
    {
        $user = ResolveMarketplaceInstallAttemptUserAction::run($attempt);

        if (! $user instanceof Authenticatable && ! $user instanceof Model) {
            return;
        }

        $body = (string) __('capell-marketplace::marketplace.updates.completed_body', [
            'name' => $attempt->extension_name,
            'version' => CapellCore::getInstalledPrettyVersion($attempt->composer_name)
                ?? ($attempt->version_constraint ?: '—'),
        ]);

        if (is_string($runtimeNotice) && $runtimeNotice !== '') {
            $body .= ' ' . $runtimeNotice;
        }

        $notification = FilamentNotification::make(MarketplaceInstallNotifications::operationId($attempt->composer_name))
            ->title((string) __('capell-marketplace::marketplace.updates.completed'))
            ->body($body)
            ->success()
            ->persistent();

        $notification->broadcast($user);

        if (Schema::hasTable('notifications')) {
            $notification->sendToDatabase($user);
        }
    }
}
