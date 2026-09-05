<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Actions\Agent\CreateAgentBlueprintAction;
use Capell\Admin\Actions\Agent\UpdateAgentSettingsAction;
use Capell\Admin\Actions\Blueprints\UpdateBlueprintAction;
use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Core\Actions\Properties\CreatePropertySetAction;
use Capell\Core\Actions\Properties\UpdatePropertySetAction;
use Capell\Core\Actions\Taxonomies\CreateTaxonomyAction;
use Capell\Core\Actions\Taxonomies\UpdateTaxonomyAction;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<AgentAdminTool> */
final class AgentAdminToolRegistry extends AbstractKeyedRegistry
{
    public function __construct(
        AgentAdminAuthorization $authorization = new AgentAdminAuthorization,
        AgentAdminPropertyValuePresenter $presenter = new AgentAdminPropertyValuePresenter,
    ) {
        $this->register(new AgentPagePropertiesReadTool($authorization, $presenter));
        $this->register(new AgentTermPropertiesReadTool($authorization, $presenter));
        $this->register(new AgentPagePropertyWriteTool($authorization, $presenter));
        $this->register(new AgentPageTermsWriteTool($authorization));
        $this->register(new AgentPageDraftSaveTool($authorization));
        $this->register(new AgentPagePublicationTool($authorization, PublicationTransition::PublishNow));
        $this->register(new AgentPagePublicationTool($authorization, PublicationTransition::SchedulePublish));
        $this->register(new AgentPageReadinessTool($authorization));
        $this->register(new AgentSiteReadinessTool($authorization));
        $this->register(new AgentSettingsWriteTool($authorization, new UpdateAgentSettingsAction));
        $this->register(new AgentBlueprintReadTool($authorization));
        $this->register(new AgentBlueprintWriteTool(
            $authorization,
            new CreateAgentBlueprintAction,
            new UpdateBlueprintAction,
        ));
        $this->register(new AgentStructureWriteTool(
            $authorization,
            new CreateTaxonomyAction,
            new UpdateTaxonomyAction,
            new CreatePropertySetAction,
            new UpdatePropertySetAction,
        ));
    }

    public function register(AgentAdminTool $tool): self
    {
        $definition = $tool->definition();
        $existing = $this->getItem($definition->name);

        if ($existing instanceof AgentAdminTool && $existing->definition()->toArray() !== $definition->toArray()) {
            throw new InvalidArgumentException(sprintf(
                'Admin agent tool [%s] is already registered with different semantics.',
                $definition->name,
            ));
        }

        $this->setItem($definition->name, $tool);

        return $this;
    }

    public function tool(string $name): AgentAdminTool
    {
        return $this->getItem($name)
            ?? throw new InvalidArgumentException(sprintf('Admin agent tool [%s] is not registered.', $name));
    }

    public function has(string $name): bool
    {
        return $this->hasItem($name);
    }

    /** @return list<AgentToolDefinitionData> */
    public function definitionsFor(Authenticatable $user, int $siteId): array
    {
        $invocation = new AgentAdminToolInvocationData(
            tool: 'admin.discovery',
            payload: [],
            siteId: $siteId,
            user: $user,
        );

        $definitions = [];

        foreach ($this->allItems() as $tool) {
            if ($tool->isAvailable($invocation)) {
                $definitions[] = $tool->definition();
            }
        }

        usort($definitions, static fn (AgentToolDefinitionData $left, AgentToolDefinitionData $right): int => $left->name <=> $right->name);

        return $definitions;
    }
}
