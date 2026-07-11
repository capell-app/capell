<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\MessageData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasBlankPlaceholder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

abstract class ResourceAlertsFilamentWidget extends Widget implements CapellFilamentWidgetContract, HasActions, HasForms
{
    use GatedByRoleAndSettings;
    use HasBlankPlaceholder;
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = '';

    protected string $view = 'capell-admin::components.widgets.alerts';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = ['default' => null];

    /**
     * @return Collection<string, MessageData>
     */
    abstract protected function buildAlerts(): Collection;

    /**
     * @return Collection<string, MessageData>
     */
    #[Computed]
    public function alerts(): Collection
    {
        return $this->buildAlerts();
    }

    #[On('refresh-alerts')]
    public function refreshAlerts(): void
    {
        unset($this->alerts);
    }
}
