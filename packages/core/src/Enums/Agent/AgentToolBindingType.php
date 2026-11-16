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

    /** Reads an on-page JSON data island addressed by a stable, semantic element id. */
    case Fragment = 'fragment';
}
