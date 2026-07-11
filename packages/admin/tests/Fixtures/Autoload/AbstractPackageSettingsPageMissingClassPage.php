<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Filament\Pages\AbstractPackageSettingsPage;

final class AbstractPackageSettingsPageMissingClassPage extends AbstractPackageSettingsPage
{
    protected static string $settingsGroup = 'abstract-page-missing';

    public function exposeSettingsClass(): string
    {
        return $this->settingsClass();
    }
}
