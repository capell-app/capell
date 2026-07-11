<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Pages;

use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class DefaultPageTableStatusResolver implements PageTableStatusResolver
{
    /**
     * A page whose visible_from is more than this many years in the future is
     * treated as a Draft (sentinel pattern). Real scheduled publishes never
     * cross this threshold — the legacy convention is now()->addYears(100).
     */
    public const DRAFT_SENTINEL_YEARS = 50;

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
        if ($page->trashed()) {
            return new PageTableStatusData(
                label: (string) __('capell-admin::table.page_status_deleted'),
                shortLabel: (string) __('capell-admin::table.page_status_deleted_short'),
                tooltip: (string) __('capell-admin::table.page_status_deleted_tooltip'),
                color: 'danger',
                icon: Heroicon::OutlinedXCircle,
                date: $this->carbonImmutable($page->deleted_at),
            );
        }

        $visibleUntil = $this->carbonImmutable($page->visible_until);
        if ($visibleUntil instanceof CarbonImmutable && $visibleUntil->isPast()) {
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

        $visibleFrom = $this->carbonImmutable($page->visible_from);
        if ($visibleFrom instanceof CarbonImmutable && $visibleFrom->isFuture()) {
            // Distinguish Draft (sentinel) from Scheduled (intentional date).
            // A visible_from past +50 years is the addYears(100) draft sentinel.
            $sentinelBoundary = CarbonImmutable::now()->addYears(self::DRAFT_SENTINEL_YEARS);
            if ($visibleFrom->greaterThan($sentinelBoundary)) {
                return new PageTableStatusData(
                    label: (string) __('capell-admin::table.page_status_draft'),
                    shortLabel: (string) __('capell-admin::table.page_status_draft_short'),
                    tooltip: (string) __('capell-admin::table.page_status_draft_tooltip'),
                    color: 'gray',
                    icon: Heroicon::OutlinedPencilSquare,
                );
            }

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

        return new PageTableStatusData(
            label: (string) __('capell-admin::table.page_status_published'),
            shortLabel: (string) __('capell-admin::table.page_status_published_short'),
            tooltip: (string) __('capell-admin::table.page_status_published_tooltip'),
            color: 'success',
            icon: Heroicon::OutlinedCheckCircle,
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
