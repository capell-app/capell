<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionDependencyGraphAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionDependencyBlockData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

final class ExtensionDependencyGraphFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.dependency_graph';

    protected string $view = 'capell-admin::widgets.extensions.dependency-graph';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 6, 'xl' => 6];

    protected static ?int $sort = 23;

    /** @return list<ExtensionDependencyBlockData> */
    #[Computed(persist: true, seconds: 60)]
    public function blockers(): array
    {
        return BuildExtensionDependencyGraphAction::run();
    }
}
