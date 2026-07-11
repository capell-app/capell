<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Extensions\Fixtures;

use Filament\Pages\Page;
use Override;

final class BuilderUnregisteredExtensionPage extends Page
{
    protected string $view = 'capell-admin::components.pages.recovery-center-stub';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Builder unregistered';
    }
}
