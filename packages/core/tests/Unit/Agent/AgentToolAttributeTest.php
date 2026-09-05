<?php

declare(strict_types=1);

use Capell\Core\Attributes\AgentTool;
use Capell\Core\Concerns\HasAgentToolDefinition;
use Capell\Core\Contracts\Agent\DefinesAgentTool;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;

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

    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}

final class MissingAttributeAgentTool implements DefinesAgentTool
{
    use HasAgentToolDefinition;

    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}

it('derives a normalised typed definition from the AgentTool attribute', function (): void {
    $definition = AttributeAgentTool::agentToolDefinition();

    expect($definition->name)->toBe('catalogue.lookup')
        ->and($definition->description)->toContain('Search published site content')
        ->and($definition->effect)->toBe(AgentToolEffect::Read)
        ->and($definition->binding->type)->toBe(AgentToolBindingType::Endpoint)
        ->and($definition->binding->target)->toBe('/agent/v1/catalogue/lookup');
});

it('rejects a typed declaration without the AgentTool attribute', function (): void {
    expect(fn (): mixed => MissingAttributeAgentTool::agentToolDefinition())
        ->toThrow(InvalidArgumentException::class, 'must declare #[AgentTool]');
});
