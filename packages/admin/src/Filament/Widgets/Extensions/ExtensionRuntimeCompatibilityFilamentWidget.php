<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionRuntimeCompatibilityAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionRuntimeCompatibilityData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

final class ExtensionRuntimeCompatibilityFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.runtime_compatibility';

    protected string $view = 'capell-admin::widgets.extensions.runtime-compatibility';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 6, 'xl' => 6];

    protected static ?int $sort = 24;

    /** @return list<ExtensionRuntimeCompatibilityData> */
    #[Computed(persist: true, seconds: 60)]
    public function checks(): array
    {
        return BuildExtensionRuntimeCompatibilityAction::run();
    }
}
