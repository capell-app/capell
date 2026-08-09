<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Filament\Pages\ThemeExtensionPage;
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

        $action = $this->activationAction($attempt);

        if ($action instanceof Action) {
            $notification->actions([$action]);
        }

        $notification->broadcast($user);

        if (Schema::hasTable('notifications')) {
            $notification->sendToDatabase($user);
        }
    }

    /**
     * The one thing the operator most likely wants next, offered where they
     * already are.
     *
     * An install that finished is not yet an install that is doing anything: a
     * theme still has to be applied to a site, and an extension still has to be
     * configured. Making the operator go and find that surface is the step most
     * installs stall on, so the completion notification carries it.
     *
     * A theme goes to its own page, which is where the existing
     * ApplyMarketplaceThemeToSitesAction flow lives — including the site scoping
     * that action takes, which a one-click activation from a toast could not ask
     * about honestly.
     */
    private function activationAction(MarketplaceInstallAttempt $attempt): ?Action
    {
        if ($attempt->kind === ExtensionKind::Theme->value) {
            $themeUrl = $this->themeActivationUrl($attempt->composer_name);

            return $themeUrl === null ? null : Action::make('activateTheme')
                ->label((string) __('capell-marketplace::marketplace.install.activate_theme_action'))
                ->icon(Heroicon::OutlinedSwatch)
                ->link()
                ->url($themeUrl);
        }

        $url = $this->extensionManagementUrl($attempt->composer_name);

        return $url === null ? null : Action::make('manageExtension')
            ->label((string) __('capell-marketplace::marketplace.install.installed_action'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->link()
            ->url($url);
    }

    /**
     * The registry is the authority on a theme's key when the package is
     * present — a theme may declare a themeKey that does not match its package
     * name — and the package-name derivation is the fallback for the window
     * where the install has completed but this process has not seen it yet.
     */
    private function themeActivationUrl(string $composerName): ?string
    {
        $manifest = CapellCore::hasPackage($composerName)
            ? CapellCore::getPackage($composerName)->manifest
            : null;

        $themeKey = $manifest instanceof CapellManifestData
            ? ThemeManifestKey::resolve($manifest)
            : ThemeManifestKey::fromPackageName($composerName);

        if ($themeKey === '') {
            return null;
        }

        try {
            return ThemeExtensionPage::getUrl(['themeKey' => $themeKey]);
        } catch (Throwable) {
            return null;
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
