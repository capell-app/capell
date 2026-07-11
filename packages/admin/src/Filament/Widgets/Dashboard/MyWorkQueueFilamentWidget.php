<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\MyWorkItemData;
use Capell\Admin\Data\Dashboard\MyWorkQueueData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;
use Spatie\LaravelData\DataCollection;

final class MyWorkQueueFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasDashboardDateRange;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected static string $settingsKey = 'my_work_queue';

    protected string $view = 'capell-admin::widgets.my-work-queue';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    protected static ?int $sort = 20;

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && self::hasWorkItems();
    }

    #[Computed(persist: true, seconds: 60)]
    public function data(): MyWorkQueueData
    {
        $user = auth()->user();

        if ($user === null) {
            return new MyWorkQueueData(
                items: MyWorkItemData::collect([], DataCollection::class),
            );
        }

        $limit = resolve(AdminSettings::class)->my_work_queue_limit;

        $data = resolve(AdminDashboardDataRequestCache::class)->myWorkQueue($user, $limit);

        if (! $this->hasDashboardPeriodFilter()) {
            return $data;
        }

        [$rangeStart, $rangeEnd] = $this->getDashboardDateRange();

        return new MyWorkQueueData(
            items: MyWorkItemData::collect(
                $data->items
                    ->toCollection()
                    ->filter(function (MyWorkItemData $item) use ($rangeStart, $rangeEnd): bool {
                        $date = $item->scheduledAt ?? $item->updatedAt;

                        if ($date === null || $date === '') {
                            return false;
                        }

                        return CarbonImmutable::parse($date)->betweenIncluded($rangeStart, $rangeEnd);
                    })
                    ->values(),
                DataCollection::class,
            ),
        );
    }

    private static function hasWorkItems(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $limit = resolve(AdminSettings::class)->my_work_queue_limit;

        return resolve(AdminDashboardDataRequestCache::class)
            ->myWorkQueue($user, $limit)
            ->items
            ->count() > 0;
    }
}
