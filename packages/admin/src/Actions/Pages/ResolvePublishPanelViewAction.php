<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PublishPanelViewData;
use Capell\Admin\Enums\PublishPanelStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Derives the {@see PublishPanelViewData} the publish panel shows for any
 * publishable record. The draft/scheduled split is decided by the Core
 * visibility-state module ({@see PublishVisibilityStateEnum}) so the far-future draft sentinel is never mistaken
 * for a real future schedule; Active/Inactive status is read from
 * {@see Statusable} when the record implements it, and is `null` otherwise.
 */
final class ResolvePublishPanelViewAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $record): PublishPanelViewData
    {
        if (! $record instanceof Publishable) {
            return $this->statusOnly($record);
        }

        $visibleFrom = $this->toImmutable($record->getAttribute('visible_from'));
        $visibleUntil = $this->toImmutable($record->getAttribute('visible_until'));

        $visibilityState = $record->publishVisibilityState();

        $isWorkspaceDraft = (int) ($record->getAttributes()['workspace_id'] ?? 0) !== 0;
        $isDraft = $isWorkspaceDraft || $visibilityState === PublishVisibilityStateEnum::draft;
        $isScheduled = ! $isWorkspaceDraft && $visibilityState === PublishVisibilityStateEnum::scheduled;

        $status = match ($isWorkspaceDraft ? PublishVisibilityStateEnum::draft : $visibilityState) {
            PublishVisibilityStateEnum::expired => PublishPanelStatusEnum::expired,
            PublishVisibilityStateEnum::draft,
            PublishVisibilityStateEnum::deleted => PublishPanelStatusEnum::draft,
            PublishVisibilityStateEnum::scheduled => PublishPanelStatusEnum::scheduled,
            PublishVisibilityStateEnum::published => PublishPanelStatusEnum::published,
        };

        return new PublishPanelViewData(
            status: $status,
            publishedAt: $this->publishedAt($record, $visibleFrom, $isDraft, $isScheduled),
            goesLiveAt: $isScheduled ? $visibleFrom : null,
            expiresAt: $isDraft ? null : $visibleUntil,
            updatedAt: $record instanceof Userstampable ? $record->updatedAt() : null,
            createdAt: $record instanceof Userstampable ? $record->createdAt() : null,
            editorName: $record instanceof Userstampable ? $this->userName($record->editorUser()) : null,
            creatorName: $record instanceof Userstampable ? $this->userName($record->creatorUser()) : null,
            enabled: $record instanceof Statusable ? $record->isEnabled() : null,
            publishable: true,
        );
    }

    private function statusOnly(Model $record): PublishPanelViewData
    {
        return new PublishPanelViewData(
            status: PublishPanelStatusEnum::draft,
            publishedAt: null,
            goesLiveAt: null,
            expiresAt: null,
            updatedAt: $record instanceof Userstampable ? $record->updatedAt() : null,
            createdAt: $record instanceof Userstampable ? $record->createdAt() : null,
            editorName: $record instanceof Userstampable ? $this->userName($record->editorUser()) : null,
            creatorName: $record instanceof Userstampable ? $this->userName($record->creatorUser()) : null,
            enabled: $record instanceof Statusable ? $record->isEnabled() : null,
            publishable: false,
        );
    }

    private function publishedAt(Model $record, ?CarbonImmutable $visibleFrom, bool $isDraft, bool $isScheduled): ?CarbonImmutable
    {
        if ($isDraft || $isScheduled) {
            return null;
        }

        // There is no `published_at` column today; guard against strict-attribute
        // access mode and fall back to a non-future `visible_from`.
        if (array_key_exists('published_at', $record->getAttributes())) {
            $publishedAt = $this->toImmutable($record->getAttribute('published_at'));

            if ($publishedAt instanceof CarbonImmutable) {
                return $publishedAt;
            }
        }

        return $visibleFrom instanceof CarbonImmutable && ! $visibleFrom->isFuture() ? $visibleFrom : null;
    }

    private function userName(mixed $user): ?string
    {
        if (! $user instanceof Model) {
            return null;
        }

        $name = $user->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
