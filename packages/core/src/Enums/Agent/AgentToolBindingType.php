<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Agent;

enum AgentToolBindingType: string
{
    case Inline = 'inline';
    case Endpoint = 'endpoint';
    case Form = 'form';
    case Search = 'search';
    case PropertyQuery = 'property-query';
}
