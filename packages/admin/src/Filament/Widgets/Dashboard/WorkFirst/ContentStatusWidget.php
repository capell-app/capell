<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildContentStatusAction;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\HasPersonalDashboardSize;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;

final class ContentStatusWidget extends Widget
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    protected string $view = 'capell-admin::widgets.dashboard.work-first-content-status';

    #[Override]
    public static function canView(): bool
    {
        return self::widgetEnabled('content_status');
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        $actor = auth()->user();

        return $actor === null || ! self::canView() ? [] : resolve(BuildContentStatusAction::class)->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage());
    }
}
