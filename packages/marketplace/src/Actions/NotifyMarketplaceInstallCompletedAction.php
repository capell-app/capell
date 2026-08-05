<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class NotifyMarketplaceInstallCompletedAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string|null  $runtimeNotice  What could not be refreshed on this
     *                                      operator's behalf. Carried in the body rather than left to the
     *                                      timeline, because an install they believe is live everywhere is
     *                                      exactly the belief this corrects.
     */
    public function handle(MarketplaceInstallAttempt $attempt, ?string $runtimeNotice = null): void
    {
        $user = ResolveMarketplaceInstallAttemptUserAction::run($attempt);

        if (! $user instanceof Authenticatable && ! $user instanceof Model) {
            return;
        }

        $body = (string) __('capell-marketplace::marketplace.install.installed_body', [
            'name' => $attempt->extension_name,
        ]);

        if (is_string($runtimeNotice) && $runtimeNotice !== '') {
            $body .= ' ' . $runtimeNotice;
        }

        $notification = FilamentNotification::make(MarketplaceInstallNotifications::operationId($attempt->composer_name))
            ->title((string) __('capell-marketplace::marketplace.install.installed'))
            ->body($body)
            ->success()
            ->persistent();

        $url = $this->extensionManagementUrl($attempt->composer_name);

        if ($url !== null) {
            $notification->actions([
                Action::make('manageExtension')
                    ->label((string) __('capell-marketplace::marketplace.install.installed_action'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->link()
                    ->url($url),
            ]);
        }

        $notification->broadcast($user);

        if (Schema::hasTable('notifications')) {
            $notification->sendToDatabase($user);
        }
    }

    private function extensionManagementUrl(string $composerName): ?string
    {
        $settingsGroup = $this->settingsGroupForPackage($composerName);

        try {
            return $settingsGroup !== null
                ? ExtensionsPage::getUrl([
                    'manage' => $composerName,
                    'surface' => $settingsGroup,
                ])
                : ExtensionsPage::getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private function settingsGroupForPackage(string $composerName): ?string
    {
        $registry = resolve(SettingsSchemaRegistry::class);

        foreach ($registry->getGroups() as $group) {
            if ($registry->getMetadata($group)?->packageName === $composerName) {
                return $group;
            }
        }

        return null;
    }
}
