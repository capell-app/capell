<?php

declare(strict_types=1);

namespace Acme\AnnouncementBar\Providers;

use Acme\AnnouncementBar\Actions\ResolveAnnouncementBarAction;
use Acme\AnnouncementBar\Admin\AnnouncementBarAdminBridge;
use Acme\AnnouncementBar\Settings\AnnouncementBarSettings;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Contracts\View\View;
use Override;
use Spatie\LaravelPackageTools\Package;

final class AnnouncementBarServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'announcement-bar';

    public static string $packageName = 'acme/announcement-bar';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('announcement-bar')
            // No hasMigration() call: settings migrations are published from
            // `database.settings` in capell.json, and hasMigration() is for
            // Laravel schema migrations only.
            ->hasViews('announcement-bar')
            ->hasTranslations();
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        $this->surface()->settingsClass('announcement-bar', AnnouncementBarSettings::class);

        $this->registerAdmin();
        $this->registerFrontend();
    }

    private function registerAdmin(): void
    {
        CapellAdmin::registerAdminBridge(self::$packageName, AnnouncementBarAdminBridge::class);
    }

    private function registerFrontend(): void
    {
        resolve(RenderHookRegistry::class)->registerCallable(
            RenderHookLocation::HeaderAfter,
            function (): ?View {
                $announcement = ResolveAnnouncementBarAction::run();

                if (! $announcement->enabled || $announcement->message === '') {
                    return null;
                }

                return view('announcement-bar::frontend.banner', [
                    'announcement' => $announcement,
                ]);
            },
        );
    }
}
