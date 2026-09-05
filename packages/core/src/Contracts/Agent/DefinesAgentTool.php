<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Agent;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Data\Agent\AgentToolDefinitionData;

/**
 * Marker for a trusted provider declaration of an agent tool.
 *
 * Manifest data is audited as data. Core resolves this contract only from a
 * booted, trusted package provider; manifests never contain executable
 * callbacks, handlers, or arbitrary class names for a tool binding.
 */
interface DefinesAgentTool extends ExtensionContribution
{
    public static function agentToolDefinition(): AgentToolDefinitionData;
}
