<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\MarketingStudio;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class MarketingStudioQuickActionsFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected static string $settingsKey = 'marketing_studio.quick_actions';

    protected string $view = 'capell-admin::widgets.marketing-studio.quick-actions';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 8, 'xl' => 8];

    protected static ?int $sort = 10;

    /**
     * @return array<string, array{section: MarketingStudioSectionEnum, actions: list<MarketingStudioActionData>}>
     */
    public function groupedActions(): array
    {
        return collect(CapellAdmin::getMarketingStudioActions())
            ->reject(fn (array $actions, string $section): bool => $section === MarketingStudioSectionEnum::Advanced->value)
            ->mapWithKeys(function (array $actions, string $section): array {
                $sectionEnum = MarketingStudioSectionEnum::from($section);

                return [
                    $section => [
                        'section' => $sectionEnum,
                        'actions' => $actions,
                    ],
                ];
            })
            ->all();
    }
}
