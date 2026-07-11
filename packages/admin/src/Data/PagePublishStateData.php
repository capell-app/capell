<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Aggregates draft/publish state for a single Page record. Consumed by the
 * PublishStatusPanel Livewire component and `PublishPanelExtender`
 * implementations. Add-on packages (publishing-studio, scheduled publishing, etc.)
 * construct and pass instances of this class.
 */
class PagePublishStateData extends Data
{
    public function __construct(
        public int $pageId,
        public bool $isDraft,
        public ?CarbonImmutable $publishedAt,
        public ?string $previewUrl,
        public ?int $contextId = null,
        public ?string $contextName = null,
        public ?string $contextStatus = null,
        public ?CarbonImmutable $scheduledPublishAt = null,
        public ?CarbonImmutable $unpublishAt = null,
    ) {}

    public function hasActiveContext(): bool
    {
        return $this->contextId !== null;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt instanceof CarbonImmutable
            && ! $this->isDraft
            && ! $this->hasScheduledPublish()
            && ! $this->isExpired();
    }

    public function hasScheduledPublish(): bool
    {
        return $this->scheduledPublishAt instanceof CarbonImmutable
            && $this->scheduledPublishAt->isFuture()
            && ! $this->isDraft;
    }

    public function hasScheduledUnpublish(): bool
    {
        return $this->unpublishAt instanceof CarbonImmutable
            && $this->unpublishAt->isFuture()
            && ! $this->isDraft;
    }

    public function isExpired(): bool
    {
        return $this->unpublishAt instanceof CarbonImmutable
            && $this->unpublishAt->isPast()
            && ! $this->isDraft;
    }

    public function statusLabel(): string
    {
        if ($this->isDraft && $this->hasActiveContext()) {
            return (string) __('capell-admin::publish_panel.status_draft_in_workspace', ['workspace' => $this->contextName]);
        }

        if ($this->isDraft) {
            return (string) __('capell-admin::publish_panel.status_draft');
        }

        if ($this->isExpired()) {
            return (string) __('capell-admin::publish_panel.status_unpublished');
        }

        if ($this->hasScheduledPublish()) {
            return (string) __('capell-admin::publish_panel.status_scheduled_publish');
        }

        if ($this->isPublished()) {
            return (string) __('capell-admin::publish_panel.status_published');
        }

        return (string) __('capell-admin::publish_panel.status_unknown');
    }
}
