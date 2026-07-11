<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionDiagnosticsAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Override;

final class ExtensionHealthFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.health';

    protected string $view = 'capell-admin::widgets.extensions.health';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 6, 'xl' => 6];

    protected static ?int $sort = 20;

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck()
            && self::hasCriticalOrWarningAlerts();
    }

    /**
     * @return list<ExtensionHealthAlertData>
     */
    #[Computed(persist: true, seconds: 60)]
    public function alerts(): array
    {
        return array_values(self::criticalOrWarningAlerts()
            ->take(5)
            ->values()
            ->all());
    }

    private static function hasCriticalOrWarningAlerts(): bool
    {
        return self::criticalOrWarningAlerts()->isNotEmpty();
    }

    /** @return Collection<int, ExtensionHealthAlertData> */
    private static function criticalOrWarningAlerts(): Collection
    {
        /** @var list<ExtensionHealthAlertData> $alerts */
        $alerts = BuildExtensionDiagnosticsAction::run();

        return collect($alerts)
            ->filter(fn (ExtensionHealthAlertData $alert): bool => in_array($alert->severity, ['critical', 'warning'], true))
            ->values();
    }
}
