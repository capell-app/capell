<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\PagePublishStateData;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePagePublishStateAction
{
    use AsObject;

    public function handle(
        Page $page,
        ?string $previewUrl = null,
        ?int $contextId = null,
        ?string $contextName = null,
        ?string $contextStatus = null,
    ): PagePublishStateData {
        $isDraft = $this->isDraft($page);

        return new PagePublishStateData(
            pageId: (int) $page->getKey(),
            isDraft: $isDraft,
            publishedAt: $isDraft ? null : $this->publishedAt($page),
            previewUrl: $previewUrl,
            contextId: $contextId,
            contextName: $contextName,
            contextStatus: $contextStatus,
            scheduledPublishAt: $isDraft ? null : $this->scheduledPublishAt($page),
            unpublishAt: $isDraft ? null : $this->dateAttribute($page, 'visible_until'),
        );
    }

    private function isDraft(Page $page): bool
    {
        return (int) ($page->getAttributes()['workspace_id'] ?? 0) !== 0;
    }

    private function publishedAt(Page $page): ?CarbonImmutable
    {
        $publishedAt = $this->dateAttribute($page, 'published_at');

        if ($publishedAt instanceof CarbonImmutable) {
            return $publishedAt;
        }

        $visibleFrom = $this->dateAttribute($page, 'visible_from');

        if ($visibleFrom instanceof CarbonImmutable && ! $visibleFrom->isFuture()) {
            return $visibleFrom;
        }

        return null;
    }

    private function scheduledPublishAt(Page $page): ?CarbonImmutable
    {
        $visibleFrom = $this->dateAttribute($page, 'visible_from');

        return $visibleFrom instanceof CarbonImmutable && $visibleFrom->isFuture()
            ? $visibleFrom
            : null;
    }

    private function dateAttribute(Page $page, string $attribute): ?CarbonImmutable
    {
        if (! array_key_exists($attribute, $page->getAttributes())) {
            return null;
        }

        $value = $page->getAttribute($attribute);

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) || is_int($value)) {
            return CarbonImmutable::parse((string) $value);
        }

        return null;
    }
}
