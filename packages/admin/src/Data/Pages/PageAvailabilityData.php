<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Data\RecordStateData;
use Capell\Core\Models\Page;
use Filament\Support\Icons\Heroicon;
use Spatie\LaravelData\Data;

final class PageAvailabilityData extends Data
{
    public function __construct(
        public readonly int $totalUrlCount,
        public readonly int $activeUrlCount,
        public readonly int $disabledUrlCount,
    ) {}

    public static function fromPage(Page $page): self
    {
        $urls = $page->relationLoaded('pageUrls') ? $page->pageUrls : $page->pageUrls()->get();
        $totalUrlCount = $urls->count();
        $activeUrlCount = $urls->where('status', true)->count();

        return new self(
            totalUrlCount: $totalUrlCount,
            activeUrlCount: $activeUrlCount,
            disabledUrlCount: $totalUrlCount - $activeUrlCount,
        );
    }

    public function state(): ?RecordStateData
    {
        if ($this->activeUrlCount === 0) {
            return new RecordStateData(
                key: 'no_active_url',
                label: (string) __('capell-admin::table.page_availability_no_active_url'),
                description: (string) __('capell-admin::table.page_availability_no_active_url_tooltip'),
                color: 'danger',
                icon: Heroicon::OutlinedEyeSlash,
                priority: 10,
            );
        }

        if ($this->disabledUrlCount > 0) {
            return new RecordStateData(
                key: 'some_urls_disabled',
                label: (string) trans_choice(
                    'capell-admin::table.page_availability_some_urls_disabled',
                    $this->disabledUrlCount,
                    ['count' => $this->disabledUrlCount],
                ),
                description: (string) trans_choice(
                    'capell-admin::table.page_availability_some_urls_disabled_tooltip',
                    $this->disabledUrlCount,
                    ['count' => $this->disabledUrlCount],
                ),
                color: 'warning',
                icon: Heroicon::OutlinedEyeSlash,
                priority: 10,
            );
        }

        return null;
    }
}
