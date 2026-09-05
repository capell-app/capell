<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Support\Agent\AgentToolRegistry;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

/** Core-authored descriptions only: page content never becomes a tool instruction. */
final class BuildAgentToolManifestAction
{
    use AsObject;

    /** @return array{capellAgentSchema: int, tools: list<array<string, mixed>>, messages: array{confirmForm: string}} */
    public function handle(bool $hasInlineData, bool $readApi = true, bool $searchAvailable = true, ?AgentToolRegistry $declaredTools = null): array
    {
        $tools = [];
        if ($hasInlineData) {
            $tools[] = $this->tool('page.get', 'inline', []);
        }

        if ($readApi) {
            $string = ['type' => 'string', 'maxLength' => 100];
            $scalar = ['anyOf' => [$string, ['type' => 'number'], ['type' => 'boolean']]];
            $operators = array_fill_keys(['eq', 'lt', 'lte', 'gt', 'gte'], $scalar);
            $operators['in'] = ['type' => 'array', 'items' => $scalar, 'maxItems' => 20];
            $operators['currency'] = $string;
            $operators['unit'] = $string;
            $tools[] = $this->tool('site.pages.query', '/agent/v1/pages', [
                'set' => $string,
                'sort' => $string,
                'filter' => ['type' => 'object', 'maxProperties' => 10, 'additionalProperties' => [
                    'type' => 'object', 'properties' => $operators, 'additionalProperties' => false,
                ]],
                'page' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                ]],
            ], ['set']);
            $tools[] = $this->tool('site.taxonomies.browse', '/agent/v1/taxonomies', [
                'key' => $string,
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
            ]);
            $tools[] = $this->tool('site.navigation', '/agent/v1/navigation', [
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
            ]);
            if ($searchAvailable) {
                $tools[] = $this->tool('site.search', '/agent/v1/search', [
                    'q' => ['type' => 'string', 'maxLength' => 200],
                ], ['q']);
            }
        }

        $reserved = array_column($tools, null, 'name');
        foreach ($declaredTools?->definitions() ?? [] as $definition) {
            $public = $definition->toPublicArray();
            if (isset($reserved[$definition->name])) {
                if (json_encode($reserved[$definition->name], JSON_THROW_ON_ERROR) !== json_encode($public, JSON_THROW_ON_ERROR)) {
                    throw new InvalidArgumentException(sprintf('Agent tool [%s] conflicts with a built-in definition.', $definition->name));
                }

                continue;
            }

            $tools[] = $public;
        }

        return ['capellAgentSchema' => 1, 'tools' => $tools, 'messages' => ['confirmForm' => __('capell-core::agent.confirm_form')]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $binding, array $properties, array $required = []): array
    {
        return new AgentToolDefinitionData(
            name: $name,
            description: __('capell-core::agent.tools.' . str_replace('.', '_', $name)),
            inputSchema: [
                'type' => 'object', 'properties' => (object) $properties,
                'required' => $required, 'additionalProperties' => false,
            ],
            outputSchema: ['type' => 'object'],
            effect: AgentToolEffect::Read,
            binding: new AgentToolBindingData(
                type: $binding === 'inline' ? AgentToolBindingType::Inline : AgentToolBindingType::Endpoint,
                target: $binding === 'inline' ? 'page' : $binding,
            ),
        )->toPublicArray();
    }
}
