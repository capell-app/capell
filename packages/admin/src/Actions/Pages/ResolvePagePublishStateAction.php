<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\PagePublishStateData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePagePublishStateAction
{
    use AsFake;
    use AsObject;

    public function handle(
        Page $page,
        ?string $previewUrl = null,
        ?int $contextId = null,
        ?string $contextName = null,
        ?string $contextStatus = null,
    ): PagePublishStateData {
        $state = $page->publishVisibilityState();
        $isDraft = (int) ($page->getAttributes()['workspace_id'] ?? 0) !== 0
            || $state === PublishVisibilityStateEnum::draft;

        return new PagePublishStateData(
            pageId: (int) $page->getKey(),
            isDraft: $isDraft,
            publishedAt: $isDraft ? null : $this->publishedAt($page),
            previewUrl: $previewUrl,
            contextId: $contextId,
            contextName: $contextName,
            contextStatus: $contextStatus,
            scheduledPublishAt: $state === PublishVisibilityStateEnum::scheduled
                ? $this->dateAttribute($page, 'visible_from')
                : null,
            unpublishAt: $isDraft ? null : $this->dateAttribute($page, 'visible_until'),
        );
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
