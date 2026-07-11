<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Filament\Pages;

use Filament\Pages\Page;
use Override;

final class PlainRegisteredExtensionPage extends Page
{
    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Plain registered extension';
    }

    #[Override]
    public static function getNavigationUrl(): string
    {
        return '/admin/plain-registered-extension';
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
