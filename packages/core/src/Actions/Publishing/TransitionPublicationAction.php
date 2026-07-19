<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing;

use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Illuminate\Support\Facades\DB;
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

        try {
            PublicationDateGuard::allow(function () use ($request, $result): void {
                DB::transaction(function () use ($request, $result): void {
                    $request->record->setAttribute('visible_from', $result->visibleFrom);
                    $request->record->setAttribute('visible_until', $result->visibleUntil);
                    $request->record->saveOrFail();
                });
            });
        } catch (Throwable) {
            return $this->evaluator->unchanged(
                $request,
                PublicationTransitionOutcome::Failed,
                'publication.transition.persistence-failed',
            );
        }

        return $result;
    }
}
