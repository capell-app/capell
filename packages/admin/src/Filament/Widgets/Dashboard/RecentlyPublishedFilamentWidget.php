<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\RecentlyPublishedData;
use Capell\Admin\Data\Dashboard\RecentlyPublishedItemData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;
use Spatie\LaravelData\DataCollection;

final class RecentlyPublishedFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasDashboardDateRange;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected static string $settingsKey = 'recently_published';

    protected string $view = 'capell-admin::widgets.recently-published';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    protected static ?int $sort = 21;

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && self::hasRecentlyPublishedItems();
    }

    #[Computed(persist: true, seconds: 60)]
    public function data(): RecentlyPublishedData
    {
        $limit = resolve(AdminSettings::class)->recently_published_limit;

        $data = resolve(AdminDashboardDataRequestCache::class)->recentlyPublished($limit);

        if (! $this->hasDashboardPeriodFilter()) {
            return $data;
        }

        [$rangeStart, $rangeEnd] = $this->getDashboardDateRange();

        return new RecentlyPublishedData(
            items: RecentlyPublishedItemData::collect(
                $data->items
                    ->toCollection()
                    ->filter(function (RecentlyPublishedItemData $item) use ($rangeStart, $rangeEnd): bool {
                        if ($item->publishedAt === null || $item->publishedAt === '') {
                            return false;
                        }

                        $publishedAt = CarbonImmutable::parse($item->publishedAt);

                        return $publishedAt->betweenIncluded($rangeStart, $rangeEnd);
                    })
                    ->values(),
                DataCollection::class,
            ),
        );
    }

    private static function hasRecentlyPublishedItems(): bool
    {
        $limit = resolve(AdminSettings::class)->recently_published_limit;

        return resolve(AdminDashboardDataRequestCache::class)
            ->recentlyPublished($limit)
            ->items
            ->count() > 0;
    }
}
