<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Settings\Schemas\Fixtures;

use Capell\Admin\Contracts\DashboardSettingsContributor;

final class FixtureDashboardContributor implements DashboardSettingsContributor
{
    public function settingsKeys(): array
    {
        return [
            ['key' => 'fixture_a', 'label' => 'Fixture A', 'group' => 'Developer'],
        ];
    }
}
