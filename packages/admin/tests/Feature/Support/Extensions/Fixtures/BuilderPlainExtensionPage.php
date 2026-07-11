<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Extensions\Fixtures;

use Filament\Pages\Page;
use Override;

final class BuilderPlainExtensionPage extends Page
{
    protected static ?int $navigationSort = 20;

    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Builder plain';
    }

    #[Override]
    public static function getNavigationUrl(): string
    {
        return '/admin/builder-plain';
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
