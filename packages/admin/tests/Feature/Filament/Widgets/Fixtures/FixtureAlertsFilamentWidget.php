<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Widgets\Fixtures;

use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Illuminate\Support\Collection;

final class FixtureAlertsFilamentWidget extends ResourceAlertsFilamentWidget
{
    /**
     * @return Collection<string, MessageData>
     */
    protected function buildAlerts(): Collection
    {
        return collect([
            'test' => new MessageData(
                message: 'Test alert message',
                type: AlertTypeEnum::Warning,
                icon: 'heroicon-o-shield-exclamation',
            ),
        ]);
    }
}
