<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Filament\Pages;

use Capell\Admin\Filament\Pages\AbstractExtensionPage;
use Override;

final class ExampleExtensionPage extends AbstractExtensionPage
{
    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationUrl(): string
    {
        return '/admin/example-extension';
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
