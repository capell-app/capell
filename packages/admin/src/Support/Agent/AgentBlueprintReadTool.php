<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\Blueprint;

final readonly class AgentBlueprintReadTool implements AgentAdminTool
{
    public function __construct(private AgentAdminAuthorization $authorization) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.blueprint.read',
            description: (string) __('capell-admin::agent.blueprint_read_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'type' => ['type' => ['string', 'null'], 'maxLength' => 64],
                ],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'blueprints' => ['type' => 'array'],
                ],
                'required' => ['blueprints'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Read,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('blueprint')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canViewBlueprints($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, [
            'id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        $this->authorization->site($invocation->user, $invocation->siteId);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        return $this->execute($invocation);
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $query = Blueprint::query();
        $id = $invocation->payload['id'] ?? null;
        $type = $invocation->payload['type'] ?? null;

        if (is_numeric($id)) {
            $query->whereKey((int) $id);
        }

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        /** @var list<array{id: int, type: string, key: string, name: string, group: string|null, order: int, status: bool, default: bool}> $blueprints */
        $blueprints = $query
            ->orderBy('type')
            ->orderBy('order')
            ->limit(100)
            ->get()
            ->map(static fn (Blueprint $blueprint): array => [
                'id' => (int) $blueprint->id,
                'type' => (string) $blueprint->getRawOriginal('type'),
                'key' => (string) $blueprint->key,
                'name' => (string) $blueprint->name,
                'group' => $blueprint->group,
                'order' => (int) $blueprint->order,
                'status' => (bool) $blueprint->status,
                'default' => (bool) $blueprint->default,
            ])
            ->values()
            ->all();

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: ['blueprints' => $blueprints],
        );
    }
}
