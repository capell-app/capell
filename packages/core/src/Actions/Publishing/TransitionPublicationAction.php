<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing;

use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Events\PublicationTransitioned;
use Capell\Core\Events\PublicationTransitioning;
use Capell\Core\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class TransitionPublicationAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly AuthorizesPublicationTransition $authorizer,
        private readonly EvaluatePublicationTransitionAction $evaluator,
    ) {}

    public function handle(PublicationTransitionRequestData $request): PublicationTransitionResultData
    {
        if (! $this->authorizer->allows($request)) {
            return $this->evaluator->unchanged(
                $request,
                PublicationTransitionOutcome::Unauthorized,
                'publication.transition.unauthorized',
            );
        }

        $result = EvaluatePublicationTransitionAction::run($request);

        if (! $result->changed()) {
            return $result;
        }

        // CAP-0460: a page moving into a publicly-visible window is hard-gated
        // by `required: publish` property completeness. Pages are the only
        // model property values attach to, and only transitions that make the
        // record MORE visible are gated — narrowing visibility (unpublish,
        // revert to draft, cancel schedule) always succeeds regardless of
        // property completeness.
        if ($request->record instanceof Page
            && in_array($request->transition, [PublicationTransition::PublishNow, PublicationTransition::SchedulePublish], true)
        ) {
            $completeness = EvaluatePropertyCompletenessAction::run($request->record);

            if ($completeness->blocksPublish()) {
                return $this->evaluator->unchanged(
                    $request,
                    PublicationTransitionOutcome::InvalidTransition,
                    'capell-core::properties.validation.missing_publish_required',
                );
            }
        }

        $transitionId = Str::uuid()->toString();

        try {
            event(new PublicationTransitioning($transitionId, $request, $result));
        } catch (Throwable $throwable) {
            report($throwable);
        }

        try {
            DB::transaction(function () use ($request, $result): void {
                $request->record->setAttribute('visible_from', $result->visibleFrom);
                $request->record->setAttribute('visible_until', $result->visibleUntil);
                $request->record->saveOrFail();
            });
        } catch (Throwable) {
            return $this->evaluator->unchanged(
                $request,
                PublicationTransitionOutcome::Failed,
                'publication.transition.persistence-failed',
            );
        }

        try {
            event(new PublicationTransitioned($transitionId, $request, $result));
        } catch (Throwable $throwable) {
            report($throwable);
        }

        return $result;
    }
}
