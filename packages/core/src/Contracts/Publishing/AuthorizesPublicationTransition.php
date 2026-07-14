<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Publishing;

use Capell\Core\Data\Publishing\PublicationTransitionRequestData;

interface AuthorizesPublicationTransition
{
    public function allows(PublicationTransitionRequestData $request): bool;
}
