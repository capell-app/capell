<?php

declare(strict_types=1);

namespace Capell\Installer\Filament\Pages;

use BackedEnum;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Installer\Support\InstallerInstallationState;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Override;

class InstallCapellPage extends Page
{
    /** @var list<array{name: string, label: string, required: bool}> */
    public array $packages = [];

    /** @var list<string> */
    public array $defaultPackageNames = [];

    protected static BackedEnum|string|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $slug = 'install-capell';

    protected static ?int $navigationSort = -100;

    protected string $view = 'capell-installer::filament.pages.install-capell-page';

    #[Override]
    public static function canAccess(): bool
    {
        return auth()->user() !== null && InstallerInstallationState::capellIsNotInstalled();
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-installer::navigation.install_capell');
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-installer::navigation.install_capell');
    }

    public function mount(): void
    {
        /** @var Collection<string, PackageData> $registeredPackages */
        $registeredPackages = CapellCore::getPackages(sortByDependencies: true);

        $this->defaultPackageNames = array_values($registeredPackages
            ->filter(fn (PackageData $package): bool => $package->isCore() || $package->isInstalled())
            ->keys()
            ->unique()
            ->values()
            ->all());

        $this->packages = array_values($registeredPackages
            ->map(fn (PackageData $package, string $packageName): array => [
                'name' => $packageName,
                'label' => $package->getLabel(),
                'required' => in_array($packageName, $this->defaultPackageNames, true),
            ])
            ->values()
            ->all());
    }
}
