<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Pages;

use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class DefaultPageTableStatusResolver implements PageTableStatusResolver
{
    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function modifyQuery(Builder $query): Builder
    {
        return $query;
    }

    public function resolve(Page $page): PageTableStatusData
    {
        return match ($page->publishVisibilityState()) {
            PublishVisibilityStateEnum::deleted => new PageTableStatusData(
                label: (string) __('capell-admin::table.page_status_deleted'),
                shortLabel: (string) __('capell-admin::table.page_status_deleted_short'),
                tooltip: (string) __('capell-admin::table.page_status_deleted_tooltip'),
                color: 'danger',
                icon: Heroicon::OutlinedXCircle,
                date: $this->carbonImmutable($page->deleted_at),
            ),
            PublishVisibilityStateEnum::expired => $this->expiredStatus($page),
            PublishVisibilityStateEnum::draft => new PageTableStatusData(
                label: (string) __('capell-admin::table.page_status_draft'),
                shortLabel: (string) __('capell-admin::table.page_status_draft_short'),
                tooltip: (string) __('capell-admin::table.page_status_draft_tooltip'),
                color: 'gray',
                icon: Heroicon::OutlinedPencilSquare,
            ),
            PublishVisibilityStateEnum::scheduled => $this->scheduledStatus($page),
            PublishVisibilityStateEnum::published => new PageTableStatusData(
                label: (string) __('capell-admin::table.page_status_published'),
                shortLabel: (string) __('capell-admin::table.page_status_published_short'),
                tooltip: (string) __('capell-admin::table.page_status_published_tooltip'),
                color: 'success',
                icon: Heroicon::OutlinedCheckCircle,
            ),
        };
    }

    private function expiredStatus(Page $page): PageTableStatusData
    {
        /** @var CarbonImmutable $visibleUntil expired state guarantees a past visible_until */
        $visibleUntil = $this->carbonImmutable($page->visible_until);

        return new PageTableStatusData(
            label: (string) __('capell-admin::table.page_status_expired'),
            shortLabel: (string) __('capell-admin::table.page_status_expired_short'),
            tooltip: (string) __('capell-admin::table.page_status_expired_tooltip', [
                'date' => $this->formatDate($visibleUntil),
            ]),
            color: 'gray',
            icon: Heroicon::OutlinedExclamationTriangle,
            date: $visibleUntil,
        );
    }

    private function scheduledStatus(Page $page): PageTableStatusData
    {
        /** @var CarbonImmutable $visibleFrom scheduled state guarantees a future visible_from */
        $visibleFrom = $this->carbonImmutable($page->visible_from);

        return new PageTableStatusData(
            label: (string) __('capell-admin::table.page_status_scheduled'),
            shortLabel: $this->shortFutureLabel($visibleFrom),
            tooltip: (string) __('capell-admin::table.page_status_scheduled_tooltip', [
                'date' => $this->formatDate($visibleFrom),
            ]),
            color: 'warning',
            icon: Heroicon::OutlinedClock,
            date: $visibleFrom,
        );
    }

    private function shortFutureLabel(CarbonImmutable $date): string
    {
        $now = CarbonImmutable::now();
        $days = (int) $now->startOfDay()->diffInDays($date->startOfDay(), false);

        if ($days >= 1) {
            return (string) __('capell-admin::table.page_status_days_short', ['count' => $days]);
        }

        $hours = max(1, (int) $now->diffInHours($date, false));

        return (string) __('capell-admin::table.page_status_hours_short', ['count' => $hours]);
    }

    private function formatDate(CarbonImmutable $date): string
    {
        return $date->translatedFormat('j F Y, H:i');
    }

    private function carbonImmutable(mixed $date): ?CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date);
        }

        if (is_string($date) || is_int($date)) {
            return CarbonImmutable::parse((string) $date);
        }

        return null;
    }
}
