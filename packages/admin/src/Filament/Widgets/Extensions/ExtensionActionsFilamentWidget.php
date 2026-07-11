<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Filament\Concerns\CustomisesExtensionsDashboard;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;
use Override;

final class ExtensionActionsFilamentWidget extends Widget implements CapellFilamentWidgetContract, HasActions, HasSchemas
{
    use CustomisesExtensionsDashboard;
    use GatedByRoleAndSettings;
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.available_actions';

    protected string $view = 'capell-admin::widgets.extensions.actions';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 12, 'xl' => 12];

    protected static ?int $sort = 25;

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck()
            && self::contentItems() !== [];
    }

    /** @return array<int, Htmlable|string> */
    public function content(): array
    {
        return self::contentItems();
    }

    public function cacheInteractsWithActions(): void
    {
        foreach ($this->getActions() as $action) {
            $this->cacheAction($action);
        }
    }

    /** @return array<int, Htmlable|string> */
    private static function contentItems(): array
    {
        $page = resolve(ExtensionsPage::class);

        return collect(app()->tagged(ExtensionsPageExtender::TAG))
            ->flatMap(fn (ExtensionsPageExtender $extender): array => $extender->getBeforeTableContent($page))
            ->values()
            ->all();
    }

    /**
     * @return array<int, Action>
     */
    private function getActions(): array
    {
        return collect(resolve(ExtensionsPageActionRegistry::class)
            ->headerActionGroupActions(resolve(ExtensionsPage::class)))
            ->push($this->customiseExtensionsDashboardAction())
            ->flatMap(fn (Action|ActionGroup $action): array => $action instanceof ActionGroup
                ? array_values($action->getFlatActions())
                : [$action])
            ->values()
            ->all();
    }
}
