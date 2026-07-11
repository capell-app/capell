<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Extensions\Fixtures;

use BackedEnum;
use Filament\Pages\Page;
use Override;

final class BuilderExampleExtensionPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-puzzle-piece';

    protected static ?int $navigationSort = 10;

    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Builder example';
    }

    #[Override]
    public static function getNavigationUrl(): string
    {
        return '/admin/builder-example';
    }

    #[Override]
    public static function getNavigationBadge(): string
    {
        return '2';
    }

    #[Override]
    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    #[Override]
    public static function getNavigationBadgeTooltip(): string
    {
        return 'Two pending actions';
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
