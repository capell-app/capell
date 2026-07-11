<?php

declare(strict_types=1);

namespace Capell\Installer\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Installer\Support\InstallerInstallationState;
use Filament\Widgets\Widget;
use Override;

final class CapellNotInstalledFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    protected string $view = 'capell-installer::widgets.capell-not-installed';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = ['default' => null];

    protected static ?int $sort = -1;

    #[Override]
    public static function canView(): bool
    {
        return InstallerInstallationState::capellIsNotInstalled();
    }

    public static function settingsKey(): string
    {
        return '';
    }

    /** @return list<string> */
    public static function rolesConfigKeys(): array
    {
        return [];
    }

    public function installerUrl(): string
    {
        return route('capell-installer.show');
    }
}
