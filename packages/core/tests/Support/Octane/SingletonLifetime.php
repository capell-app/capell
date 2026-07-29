<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Octane;

enum SingletonLifetime: string
{
    case BootImmutable = 'boot-immutable';
    case RequestMutable = 'request-mutable';
    case Stateless = 'stateless';
}
