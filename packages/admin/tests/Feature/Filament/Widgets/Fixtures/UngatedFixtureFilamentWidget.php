<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Widgets\Fixtures;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class UngatedFixtureFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = '';

    protected string $view = 'capell-admin::widgets.stub';
}
