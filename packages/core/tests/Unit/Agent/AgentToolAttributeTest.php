<?php

declare(strict_types=1);

use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Tests\Support\AttributeAgentTool;
use Capell\Core\Tests\Support\MissingAttributeAgentTool;

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
