<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildWorkFirstAttentionAction;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\ChecksWorkFirstWidgetAccess;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;

final class UrgentSummaryWidget extends Widget
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;

    protected string $view = 'capell-admin::widgets.dashboard.work-first-urgent-summary';

    protected int|string|array $columnSpan = 'full';

    #[Override]
    public static function canView(): bool
    {
        return self::widgetEnabled('urgent_summary');
    }

    /** @return list<DashboardAttentionItemData> */
    #[Computed]
    public function items(): array
    {
        $actor = auth()->user();
        if (! self::canView() || $actor === null) {
            return [];
        }

        return array_values(array_filter(
            resolve(BuildWorkFirstAttentionAction::class)->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage()),
            static fn (DashboardAttentionItemData $item): bool => $item->urgent,
        ));
    }
}
