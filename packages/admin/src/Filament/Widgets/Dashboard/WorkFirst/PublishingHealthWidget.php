<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildWorkFirstPublishingAction;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Data\Dashboard\DashboardPublicationItemData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\HasPersonalDashboardSize;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;

final class PublishingHealthWidget extends Widget
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    public bool $attentionVisible = true;

    protected string $view = 'capell-admin::widgets.dashboard.work-first-publishing';

    #[Override]
    public static function canView(): bool
    {
        return self::widgetEnabled('publishing_health');
    }

    /** @return list<DashboardPublicationItemData> */
    #[Computed]
    public function items(): array
    {
        $actor = auth()->user();

        return ! self::canView() || $actor === null ? [] : resolve(BuildWorkFirstPublishingAction::class)
            ->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage());
    }

    #[Computed]
    public function blockerCount(): int
    {
        $actor = auth()->user();
        if (! self::canView() || $actor === null) {
            return 0;
        }

        return count(array_filter(
            resolve(AdminDashboardDataRequestCache::class)->attentionSnapshot($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage()),
            static fn (DashboardAttentionItemData $item): bool => $item->urgent || $item->deadline !== null,
        ));
    }
}
