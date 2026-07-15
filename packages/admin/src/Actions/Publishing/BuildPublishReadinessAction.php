<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Data\Publishing\PublishReadinessData;
use Capell\Core\Actions\Publishing\EvaluatePublicationTransitionAction;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublishReadinessAction
{
    use AsObject;

    public function handle(
        Model&Publishable $record,
        ?PublicationTransitionRequestData $previewRequest = null,
    ): PublishReadinessData {
        $state = $record->publishVisibilityState();
        $blockers = $this->blockers($record);
        $publishedAt = $this->date($record->getAttribute('visible_from'));
        $unpublishAt = $this->date($record->getAttribute('visible_until'));

        return new PublishReadinessData(
            currentState: $state,
            blockingCheckIds: $blockers,
            scheduledPublishAt: $state === PublishVisibilityStateEnum::scheduled ? $publishedAt : null,
            scheduledUnpublishAt: $unpublishAt?->isFuture() === true ? $unpublishAt : null,
            publicEligible: $state === PublishVisibilityStateEnum::published && $blockers === [],
            allowedTransitions: $this->allowedTransitions($state),
            preview: $previewRequest instanceof PublicationTransitionRequestData
                ? EvaluatePublicationTransitionAction::run($previewRequest)
                : null,
        );
    }

    /**
     * @param  iterable<Model&Publishable>  $records
     * @return list<PublishReadinessData>
     */
    public function handleMany(iterable $records): array
    {
        $readiness = [];

        foreach ($records as $record) {
            $readiness[] = $this->handle($record);
        }

        return $readiness;
    }

    /** @return list<string> */
    private function blockers(Model&Publishable $record): array
    {
        if (! $record instanceof Page) {
            return [];
        }

        $blockers = [];

        if ($record->blueprint_id === null || ! $record->relationLoaded('blueprint') && ! $record->blueprint()->exists()) {
            $blockers[] = 'publishing.blueprint.missing';
        }

        if ($record->layout_id === null || ! $record->relationLoaded('layout') && ! $record->layout()->exists()) {
            $blockers[] = 'publishing.layout.missing';
        }

        if ($record->exists && ! $record->translations()->exists()) {
            $blockers[] = 'publishing.translation.missing';
        }

        if ($record->exists && ! $record->pageUrls()->where('status', true)->exists()) {
            $blockers[] = 'publishing.url.active-missing';
        }

        return $blockers;
    }

    /** @return list<string> */
    private function allowedTransitions(PublishVisibilityStateEnum $state): array
    {
        if ($state === PublishVisibilityStateEnum::deleted) {
            return [];
        }

        return array_values(array_map(
            static fn (PublicationTransition $transition): string => $transition->value,
            match ($state) {
                PublishVisibilityStateEnum::draft => [PublicationTransition::PublishNow, PublicationTransition::SchedulePublish],
                PublishVisibilityStateEnum::scheduled => [PublicationTransition::PublishNow, PublicationTransition::SchedulePublish, PublicationTransition::ScheduleUnpublish, PublicationTransition::RevertToDraft],
                PublishVisibilityStateEnum::published => [PublicationTransition::ScheduleUnpublish, PublicationTransition::Unpublish, PublicationTransition::RevertToDraft],
                default => [PublicationTransition::PublishNow, PublicationTransition::SchedulePublish, PublicationTransition::RevertToDraft],
            },
        ));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof CarbonImmutable => $value,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) => CarbonImmutable::parse($value),
            default => null,
        };
    }
}
