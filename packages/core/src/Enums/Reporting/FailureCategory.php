<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Reporting;

enum FailureCategory: string
{
    case Configuration = 'configuration';
    case Validation = 'validation';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Dependency = 'dependency';
    case Timeout = 'timeout';
    case RateLimit = 'rate_limit';
    case Persistence = 'persistence';
    case Capacity = 'capacity';
    case Runtime = 'runtime';
}
