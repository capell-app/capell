<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Attributes\AgentTool;
use Capell\Core\Data\Agent\AgentToolDefinitionData;

trait HasAgentToolDefinition
{
    public static function agentToolDefinition(): AgentToolDefinitionData
    {
        return AgentTool::for(static::class);
    }
}
