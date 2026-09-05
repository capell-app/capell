<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Actions\Properties\CreatePropertySetAction;
use Capell\Core\Actions\Properties\UpdatePropertySetAction;
use Capell\Core\Actions\Taxonomies\CreateTaxonomyAction;
use Capell\Core\Actions\Taxonomies\UpdateTaxonomyAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final readonly class AgentStructureWriteTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.structure.write',
            description: (string) __('capell-admin::agent.structure_write_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'resource' => ['type' => 'string', 'enum' => ['taxonomy', 'property_set']],
                    'operation' => ['type' => 'string', 'enum' => ['create', 'update']],
                    'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'data' => ['type' => 'object', 'minProperties' => 1],
                ],
                'required' => ['resource', 'operation', 'data'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'resource' => ['type' => 'string'],
                    'operation' => ['type' => 'string'],
                    'id' => ['type' => 'integer'],
                    'saved' => ['type' => 'boolean'],
                ],
                'required' => ['resource', 'operation', 'id', 'saved'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('structure')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canUpdateSite($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, [
            'resource' => ['required', 'string', 'in:taxonomy,property_set'],
            'operation' => ['required', 'string', 'in:create,update'],
            'id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'data' => ['required', 'array', 'min:1'],
        ])->validate();

        $resource = (string) $invocation->payload['resource'];
        // Taxonomies belong to a site; property sets are shared across every site.
        throw_unless(
            $resource === 'taxonomy'
                ? $this->authorization->canUpdateSite($invocation->user, $invocation->siteId)
                : $this->authorization->canUpdateGlobalSettings($invocation->user, $invocation->siteId),
            AuthorizationException::class,
        );

        $operation = (string) $invocation->payload['operation'];
        $id = $invocation->payload['id'] ?? null;

        if ($operation === 'update' && (! is_int($id) && ! is_numeric($id))) {
            throw ValidationException::withMessages(['id' => __('capell-admin::agent.structure_id_required')]);
        }

        if ($resource === 'taxonomy') {
            $taxonomy = $id === null ? null : Taxonomy::query()
                ->whereKey((int) $id)
                ->where('site_id', $invocation->siteId)
                ->firstOrFail();

            if ($taxonomy instanceof Taxonomy && $operation === 'update') {
                $this->authorization->site($invocation->user, $invocation->siteId);
            }
        } elseif ($operation === 'update') {
            $propertySet = PropertySet::query()->whereKey((int) $id)->firstOrFail();

            if ($propertySet->owner_package !== null) {
                throw ValidationException::withMessages([
                    'property_set' => __('capell-core::properties.validation.property_set_owned'),
                ]);
            }
        }

        $this->authorization->site($invocation->user, $invocation->siteId);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        return new AgentAdminToolResultData(
            ok: true,
            mode: 'preview',
            tool: $invocation->tool,
            data: [
                'resource' => $invocation->payload['resource'],
                'operation' => $invocation->payload['operation'],
                'id' => $invocation->payload['id'] ?? null,
                'before' => $this->current($invocation),
                'data' => $this->data($invocation),
            ],
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $resource = (string) $invocation->payload['resource'];
        $operation = (string) $invocation->payload['operation'];
        $data = $this->data($invocation);
        $id = $invocation->payload['id'] ?? null;

        if ($resource === 'taxonomy') {
            $site = $this->authorization->site($invocation->user, $invocation->siteId);
            $taxonomy = $operation === 'create'
                ? CreateTaxonomyAction::run($site, $data)
                : UpdateTaxonomyAction::run(Taxonomy::query()->whereKey((int) $id)->where('site_id', $site->id)->firstOrFail(), $data);
            $savedId = $taxonomy->id;
        } else {
            $propertySet = $operation === 'create'
                ? CreatePropertySetAction::run($data)
                : UpdatePropertySetAction::run(PropertySet::query()->whereKey((int) $id)->firstOrFail(), $data);
            $savedId = $propertySet->id;
        }

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'resource' => $resource,
                'operation' => $operation,
                'id' => $savedId,
                'saved' => true,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function data(AgentAdminToolInvocationData $invocation): array
    {
        $data = $invocation->payload['data'] ?? null;

        if (! is_array($data)) {
            throw ValidationException::withMessages(['data' => __('capell-admin::agent.structure_data_invalid')]);
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    private function current(AgentAdminToolInvocationData $invocation): ?array
    {
        if (($invocation->payload['operation'] ?? null) !== 'update' || ! is_numeric($invocation->payload['id'] ?? null)) {
            return null;
        }

        if (($invocation->payload['resource'] ?? null) === 'taxonomy') {
            $taxonomy = Taxonomy::query()
                ->whereKey((int) $invocation->payload['id'])
                ->where('site_id', $invocation->siteId)
                ->firstOrFail();

            return [
                'id' => $taxonomy->id,
                'key' => $taxonomy->key,
                'name' => $taxonomy->name,
                'hierarchical' => $taxonomy->hierarchical,
                'property_set_id' => $taxonomy->property_set_id,
                'position' => $taxonomy->position,
            ];
        }

        $propertySet = PropertySet::query()->whereKey((int) $invocation->payload['id'])->firstOrFail();

        return [
            'id' => $propertySet->id,
            'key' => $propertySet->key,
            'name' => $propertySet->name,
            'version' => $propertySet->version,
            'owner_package' => $propertySet->owner_package,
        ];
    }
}
