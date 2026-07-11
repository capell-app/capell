<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class SettingsGatedWidgetAlpha extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var array<int, string> */
    protected static array $rolesConfigKeys = ['super_admin'];

    protected static string $settingsKey = 'widget_alpha';

    /** @var view-string */
    protected string $view = 'capell-admin::widgets.stub';
}
