<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Filament\Pages;

use Filament\Pages\Page;
use Override;

final class NegativeSortExtensionPage extends Page
{
    protected static ?int $navigationSort = -100;

    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Negative sort extension';
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
