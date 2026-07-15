<?php

declare(strict_types=1);

namespace Capell\Core\Support\Publishing;

use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Illuminate\Support\Facades\Gate;

final class GatePublicationTransitionAuthorizer implements AuthorizesPublicationTransition
{
    public function allows(PublicationTransitionRequestData $request): bool
    {
        return Gate::forUser($request->actor)->allows('update', $request->record);
    }
}
