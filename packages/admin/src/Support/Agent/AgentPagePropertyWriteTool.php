<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\Page;
use Illuminate\Validation\ValidationException;

final readonly class AgentPagePropertyWriteTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
        private AgentAdminPropertyValuePresenter $presenter,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.page.properties.write',
            description: (string) __('capell-admin::agent.page_property_write_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'minimum' => 1],
                    'property_key' => ['type' => 'string', 'minLength' => 1],
                    'value' => [],
                    'currency' => ['type' => ['string', 'null']],
                    'unit' => ['type' => ['string', 'null']],
                    'position' => ['type' => 'integer', 'minimum' => 0],
                    'translation_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                ],
                'required' => ['page_id', 'property_key', 'value'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'property' => ['type' => 'object'],
                ],
                'required' => ['page_id', 'property'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('page/properties')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canUpdatePages($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, [
            'page_id' => ['required', 'integer', 'min:1'],
            'property_key' => ['required', 'string', 'max:191'],
            'value' => ['present'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:12'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'translation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        $this->authorization->page($invocation, 'update');
        $this->resolveDefinition($invocation);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $definition = $this->resolveDefinition($invocation);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'preview',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'property' => [
                    'key' => $definition->qualifiedKey(),
                    'type' => $definition->type->value,
                    'previous' => $this->currentValue($page, $definition, (int) ($invocation->payload['position'] ?? 0), $invocation->payload['translation_id'] ?? null),
                    'value' => $invocation->payload['value'],
                    'currency' => $invocation->payload['currency'] ?? null,
                    'unit' => $invocation->payload['unit'] ?? null,
                    'position' => (int) ($invocation->payload['position'] ?? 0),
                    'translation_id' => $invocation->payload['translation_id'] ?? null,
                ],
            ],
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $definition = $this->resolveDefinition($invocation);

        SetPagePropertyValuesAction::run($page, [new PropertyValueData(
            propertyKey: $definition->key,
            type: $definition->type,
            value: $invocation->payload['value'],
            currency: $invocation->payload['currency'] ?? null,
            unit: $invocation->payload['unit'] ?? null,
            position: (int) ($invocation->payload['position'] ?? 0),
            translationId: $invocation->payload['translation_id'] ?? null,
        )]);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'property' => ['key' => $definition->qualifiedKey(), 'updated' => true],
            ],
        );
    }

    private function resolveDefinition(AgentAdminToolInvocationData $invocation): EffectivePropertyDefinitionData
    {
        $page = $this->authorization->page($invocation, 'update');
        $key = (string) $invocation->payload['property_key'];
        $definition = ResolveEffectiveDefinitionsAction::run($page)->first(
            static fn (EffectivePropertyDefinitionData $candidate): bool => $candidate->key === $key || $candidate->qualifiedKey() === $key,
        );

        if (! $definition instanceof EffectivePropertyDefinitionData) {
            throw ValidationException::withMessages(['property_key' => __('capell-admin::agent.property_not_attached')]);
        }

        return $definition;
    }

    /** @return array<string, mixed>|null */
    private function currentValue(Page $page, EffectivePropertyDefinitionData $definition, int $position, ?int $translationId): ?array
    {
        foreach ($this->presenter->page($page) as $value) {
            if (($value['key'] ?? null) !== $definition->qualifiedKey()) {
                continue;
            }

            if (($value['position'] ?? null) !== $position) {
                continue;
            }

            if (($value['translation_id'] ?? null) !== $translationId) {
                continue;
            }

            return $value;
        }

        return null;
    }
}
