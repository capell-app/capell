<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\ListExtensionAuditEventsAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionAuditEventData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

final class RecentlyChangedExtensionsFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.recently_changed';

    protected string $view = 'capell-admin::widgets.extensions.recently-changed';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 6, 'xl' => 6];

    protected static ?int $sort = 26;

    /** @return list<ExtensionAuditEventData> */
    #[Computed(persist: true, seconds: 60)]
    public function events(): array
    {
        return ListExtensionAuditEventsAction::run();
    }
}
