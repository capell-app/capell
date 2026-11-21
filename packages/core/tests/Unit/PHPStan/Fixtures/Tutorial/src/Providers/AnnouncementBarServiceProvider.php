<?php

declare(strict_types=1);

namespace Acme\AnnouncementBar\Providers;

use Acme\AnnouncementBar\Actions\ResolveAnnouncementBarAction;
use Acme\AnnouncementBar\Admin\AnnouncementBarAdminBridge;
use Acme\AnnouncementBar\Data\AnnouncementBarData;
use Acme\AnnouncementBar\Settings\AnnouncementBarSettings;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Events\FrontendContextResolved;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Event;
use Override;
use Spatie\LaravelPackageTools\Package;

final class AnnouncementBarServiceProvider extends AbstractPackageServiceProvider
{
    private const string RENDER_DATA_KEY = 'acme.announcement-bar';

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
        Event::listen(FrontendContextResolved::class, static function (FrontendContextResolved $event): void {
            resolve(FrontendContextReader::class)->setFrontendData(
                self::RENDER_DATA_KEY,
                $event->context->isError() ? null : ResolveAnnouncementBarAction::run(),
            );
        });

        resolve(RenderHookRegistry::class)->registerCallable(
            RenderHookLocation::HeaderAfter,
            function (): ?View {
                $announcement = resolve(FrontendContextReader::class)->getFrontendData(self::RENDER_DATA_KEY);

                if (! $announcement instanceof AnnouncementBarData || ! $announcement->enabled || $announcement->message === '') {
                    return null;
                }

                return view('announcement-bar::frontend.banner', [
                    'announcement' => $announcement,
                ]);
            },
        );
    }
}
