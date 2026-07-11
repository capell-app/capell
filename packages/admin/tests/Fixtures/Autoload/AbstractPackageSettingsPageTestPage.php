<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Filament\Pages\AbstractPackageSettingsPage;

final class AbstractPackageSettingsPageTestPage extends AbstractPackageSettingsPage
{
    protected static string $settingsGroup = 'abstract-page-test';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function exposeMutateBeforeFill(array $data): array
    {
        return $this->mutateFormDataBeforeFill($data);
    }

    public function exposeSettingsClass(): string
    {
        return $this->settingsClass();
    }
}
