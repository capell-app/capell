<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Enums\PublishPanelStatusEnum;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Presentation-ready snapshot of a page's publish state for the
 * {@see PublishStatusPanel} Blade view. Built by
 * {@see ResolvePublishPanelViewAction} so the panel
 * never derives state inline and the rules stay testable in isolation.
 */
class PublishPanelViewData extends Data
{
    public function __construct(
        public readonly PublishPanelStatusEnum $status,
        public readonly ?CarbonImmutable $publishedAt,
        public readonly ?CarbonImmutable $goesLiveAt,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly ?CarbonImmutable $createdAt,
        public readonly ?string $editorName,
        public readonly ?string $creatorName,
        // null when the record is not Statusable; otherwise its Active/Inactive state.
        public readonly ?bool $enabled = null,
        // false for Statusable-only records that have no publish lifecycle.
        public readonly bool $publishable = true,
    ) {}

    public function isStatusable(): bool
    {
        return $this->enabled !== null;
    }

    public function isPublishable(): bool
    {
        return $this->publishable;
    }

    public function isEnabled(): bool
    {
        return $this->enabled === true;
    }

    public function isLive(): bool
    {
        return $this->status === PublishPanelStatusEnum::published;
    }

    public function isScheduled(): bool
    {
        return $this->status === PublishPanelStatusEnum::scheduled;
    }

    public function isDraft(): bool
    {
        return $this->status === PublishPanelStatusEnum::draft;
    }

    public function isExpired(): bool
    {
        return $this->status === PublishPanelStatusEnum::expired;
    }

    public function hasScheduledUnpublish(): bool
    {
        return $this->expiresAt instanceof CarbonImmutable && $this->expiresAt->isFuture();
    }
}
