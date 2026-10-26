<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RestoreMainDashboardLayoutAction
{
    use AsFake;
    use AsObject;

    /** @return list<array{key: string, enabled: bool, order: int}> */
    public function handle(): array
    {
        $order = AdminSettings::defaultWidgetOrder();
        $enabled = [...array_keys($order), ...CapellAdmin::getDefaultEnabledOverviewStatKeys()];

        return array_map(fn (array $widget): array => [
            'key' => $widget['key'],
            'enabled' => in_array($widget['key'], $enabled, true),
            'order' => $order[$widget['key']] ?? 999,
        ], DashboardSettingsSchema::allContributedKeys(DashboardEnum::Main));
    }
}
