<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Publishing;

enum PublicationTransitionOutcome: string
{
    case Changed = 'changed';
    case AlreadyCorrect = 'already-correct';
    case Unauthorized = 'unauthorized';
    case InvalidTransition = 'invalid-transition';
    case Failed = 'failed';
}
