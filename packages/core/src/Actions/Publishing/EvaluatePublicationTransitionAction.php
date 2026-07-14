<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing;

use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsObject;

final class EvaluatePublicationTransitionAction
{
    use AsObject;

    public function handle(PublicationTransitionRequestData $request): PublicationTransitionResultData
    {
        $beforeFrom = $this->date($request->record->getAttribute('visible_from'));
        $beforeUntil = $this->date($request->record->getAttribute('visible_until'));
        $beforeState = PublishVisibilityStateEnum::fromDates(
            $beforeFrom,
            $beforeUntil,
            $request->record->trashed(),
            $request->now,
        );

        if ($beforeState === PublishVisibilityStateEnum::deleted) {
            return $this->result(
                PublicationTransitionOutcome::InvalidTransition,
                $beforeState,
                $beforeFrom,
                $beforeUntil,
                $request,
                'publication.transition.deleted',
            );
        }

        [$visibleFrom, $visibleUntil, $invalidReason] = match ($request->transition) {
            PublicationTransition::PublishNow => $this->publishNow(
                $request,
                $beforeState,
                $beforeFrom,
                $beforeUntil,
            ),
            PublicationTransition::RevertToDraft => [
                $beforeState === PublishVisibilityStateEnum::draft
                    ? $beforeFrom
                    : PublishSentinel::draftValue($request->now),
                null,
                null,
            ],
            PublicationTransition::SchedulePublish => $this->schedulePublish($request, $beforeUntil),
            PublicationTransition::ScheduleUnpublish => $this->scheduleUnpublish($request, $beforeFrom),
            PublicationTransition::Unpublish => [$beforeFrom, $request->now, null],
        };

        if (is_string($invalidReason)) {
            return $this->result(
                PublicationTransitionOutcome::InvalidTransition,
                $beforeState,
                $beforeFrom,
                $beforeUntil,
                $request,
                $invalidReason,
            );
        }

        if ($this->same($beforeFrom, $visibleFrom) && $this->same($beforeUntil, $visibleUntil)) {
            return $this->result(
                PublicationTransitionOutcome::AlreadyCorrect,
                $beforeState,
                $visibleFrom,
                $visibleUntil,
                $request,
                'publication.transition.already-correct',
            );
        }

        return $this->result(
            PublicationTransitionOutcome::Changed,
            $beforeState,
            $visibleFrom,
            $visibleUntil,
            $request,
            'publication.transition.changed',
        );
    }

    public function unchanged(
        PublicationTransitionRequestData $request,
        PublicationTransitionOutcome $outcome,
        string $reasonKey,
    ): PublicationTransitionResultData {
        $visibleFrom = $this->date($request->record->getAttribute('visible_from'));
        $visibleUntil = $this->date($request->record->getAttribute('visible_until'));
        $state = PublishVisibilityStateEnum::fromDates(
            $visibleFrom,
            $visibleUntil,
            $request->record->trashed(),
            $request->now,
        );

        return new PublicationTransitionResultData(
            outcome: $outcome,
            beforeState: $state,
            afterState: $state,
            visibleFrom: $visibleFrom,
            visibleUntil: $visibleUntil,
            reasonKey: $reasonKey,
        );
    }

    /** @return array{?CarbonImmutable, ?CarbonImmutable, null} */
    private function publishNow(
        PublicationTransitionRequestData $request,
        PublishVisibilityStateEnum $beforeState,
        ?CarbonImmutable $visibleFrom,
        ?CarbonImmutable $visibleUntil,
    ): array {
        if ($beforeState === PublishVisibilityStateEnum::published) {
            return [$visibleFrom, $visibleUntil, null];
        }

        return [
            $request->now,
            $visibleUntil?->lessThanOrEqualTo($request->now) === true ? null : $visibleUntil,
            null,
        ];
    }

    /** @return array{?CarbonImmutable, ?CarbonImmutable, ?string} */
    private function schedulePublish(
        PublicationTransitionRequestData $request,
        ?CarbonImmutable $visibleUntil,
    ): array {
        $requestedTime = $request->requestedTime;

        if (! $requestedTime instanceof CarbonImmutable || ! $requestedTime->greaterThan($request->now)) {
            return [null, $visibleUntil, 'publication.transition.requested-time-not-future'];
        }

        if ($visibleUntil instanceof CarbonImmutable && $requestedTime->greaterThanOrEqualTo($visibleUntil)) {
            return [null, $visibleUntil, 'publication.transition.publish-not-before-unpublish'];
        }

        return [$requestedTime, $visibleUntil, null];
    }

    /** @return array{?CarbonImmutable, ?CarbonImmutable, ?string} */
    private function scheduleUnpublish(
        PublicationTransitionRequestData $request,
        ?CarbonImmutable $visibleFrom,
    ): array {
        $requestedTime = $request->requestedTime;

        if (! $requestedTime instanceof CarbonImmutable || ! $requestedTime->greaterThan($request->now)) {
            return [$visibleFrom, null, 'publication.transition.requested-time-not-future'];
        }

        if ($visibleFrom instanceof CarbonImmutable
            && $visibleFrom->greaterThan($request->now)
            && ! PublishSentinel::isDraftValue($visibleFrom, $request->now)
            && ! $requestedTime->greaterThan($visibleFrom)) {
            return [$visibleFrom, null, 'publication.transition.unpublish-not-after-publish'];
        }

        return [$visibleFrom, $requestedTime, null];
    }

    private function result(
        PublicationTransitionOutcome $outcome,
        PublishVisibilityStateEnum $beforeState,
        ?CarbonImmutable $visibleFrom,
        ?CarbonImmutable $visibleUntil,
        PublicationTransitionRequestData $request,
        string $reasonKey,
    ): PublicationTransitionResultData {
        return new PublicationTransitionResultData(
            outcome: $outcome,
            beforeState: $beforeState,
            afterState: PublishVisibilityStateEnum::fromDates(
                $visibleFrom,
                $visibleUntil,
                $request->record->trashed(),
                $request->now,
            ),
            visibleFrom: $visibleFrom,
            visibleUntil: $visibleUntil,
            reasonKey: $reasonKey,
        );
    }

    private function same(?CarbonImmutable $first, ?CarbonImmutable $second): bool
    {
        if (! $first instanceof CarbonImmutable || ! $second instanceof CarbonImmutable) {
            return $first === null && $second === null;
        }

        return $first->equalTo($second);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof CarbonImmutable => $value,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value), is_int($value) => CarbonImmutable::parse((string) $value),
            default => null,
        };
    }
}
