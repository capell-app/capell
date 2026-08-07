<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Tell whoever asked for the uninstall that it finished, and what is left.
 *
 * Separate from the install and update notifications for the same reason those
 * are separate from each other: what this audience needs to know is not a
 * version and not a next step, but whether the package files and the
 * extension's data are still on this site. Both answers are choices the
 * operator made minutes ago on a modal they have since closed, and a
 * notification that omits them leaves the one question an uninstall raises
 * unanswered.
 */
final class NotifyMarketplaceUninstallCompletedAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string|null  $runtimeNotice  What could not be refreshed on this
     *                                      operator's behalf. Carried in the body rather than left to the
     *                                      timeline, because an extension they believe is gone everywhere is
     *                                      exactly the belief this corrects.
     */
    public function handle(MarketplaceInstallAttempt $attempt, ?string $runtimeNotice = null): void
    {
        $user = ResolveMarketplaceInstallAttemptUserAction::run($attempt);

        if (! $user instanceof Authenticatable && ! $user instanceof Model) {
            return;
        }

        $options = MarketplaceUninstallOptionsData::fromPayload($attempt->uninstall_options);
        $body = (string) __($options->deletePackage
            ? 'capell-marketplace::marketplace.uninstalls.completed_body_deleted'
            : 'capell-marketplace::marketplace.uninstalls.completed_body_retained', [
                'name' => $attempt->extension_name,
            ]);

        if ($options->deleteData) {
            $body .= ' ' . __('capell-marketplace::marketplace.uninstalls.completed_body_data_deleted');
        }

        if (is_string($runtimeNotice) && $runtimeNotice !== '') {
            $body .= ' ' . $runtimeNotice;
        }

        $notification = FilamentNotification::make(MarketplaceInstallNotifications::operationId($attempt->composer_name))
            ->title((string) __('capell-marketplace::marketplace.uninstalls.completed', [
                'name' => $attempt->extension_name,
            ]))
            ->body($body)
            ->success()
            ->persistent();

        $notification->broadcast($user);

        if (Schema::hasTable('notifications')) {
            $notification->sendToDatabase($user);
        }
    }
}
