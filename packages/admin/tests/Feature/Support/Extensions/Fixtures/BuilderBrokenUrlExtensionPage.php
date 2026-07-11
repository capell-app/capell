<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Extensions\Fixtures;

use Filament\Pages\Page;
use Override;
use RuntimeException;

final class BuilderBrokenUrlExtensionPage extends Page
{
    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Builder broken';
    }

    #[Override]
    public static function getNavigationUrl(): string
    {
        throw new RuntimeException('Navigation URL could not be resolved.');
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
