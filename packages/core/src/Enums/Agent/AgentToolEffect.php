<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Agent;

enum AgentToolEffect: string
{
    case Read = 'read';
    case Write = 'write';
}
