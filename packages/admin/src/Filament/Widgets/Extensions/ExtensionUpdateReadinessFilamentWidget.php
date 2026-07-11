<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

final class ExtensionUpdateReadinessFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.update_readiness';

    protected string $view = 'capell-admin::widgets.extensions.update-readiness';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 6, 'xl' => 6];

    protected static ?int $sort = 22;

    /** @return list<ExtensionUpdateReadinessData> */
    #[Computed(persist: true, seconds: 60)]
    public function updates(): array
    {
        return BuildExtensionUpdateReadinessAction::run();
    }
}
