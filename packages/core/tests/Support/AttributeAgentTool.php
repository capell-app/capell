<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Attributes\AgentTool;
use Capell\Core\Concerns\HasAgentToolDefinition;
use Capell\Core\Contracts\Agent\DefinesAgentTool;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Override;

#[AgentTool(
    name: 'catalogue.lookup',
    descriptionKey: 'capell-core::agent.tools.site_search',
    inputSchema: ['type' => 'object', 'additionalProperties' => false],
    outputSchema: ['type' => 'object', 'additionalProperties' => false],
    effect: AgentToolEffect::Read,
    bindingType: AgentToolBindingType::Endpoint,
    bindingTarget: '/agent/v1/catalogue/lookup',
)]
final class AttributeAgentTool implements DefinesAgentTool
{
    use HasAgentToolDefinition;

    #[Override]
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
