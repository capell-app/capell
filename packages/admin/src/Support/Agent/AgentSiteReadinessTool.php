<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Collection;

final readonly class AgentSiteReadinessTool implements AgentAdminTool
{
    public function __construct(private AgentAdminAuthorization $authorization) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.site.agent_readiness.read',
            description: (string) __('capell-admin::agent.site_readiness_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'site_id' => ['type' => 'integer'],
                    'pages_checked' => ['type' => 'integer'],
                    'complete_pages' => ['type' => 'integer'],
                    'incomplete_pages' => ['type' => 'integer'],
                    'missing_publish_required' => ['type' => 'integer'],
                    'missing_contract_required' => ['type' => 'integer'],
                ],
                'required' => [
                    'site_id',
                    'pages_checked',
                    'complete_pages',
                    'incomplete_pages',
                    'missing_publish_required',
                    'missing_contract_required',
                ],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Read,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('site/readiness')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canViewPages($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, [])->validate();
        $this->authorization->site($invocation->user, $invocation->siteId);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        return $this->execute($invocation);
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $this->authorization->site($invocation->user, $invocation->siteId);
        $pagesChecked = 0;
        $completePages = 0;
        $missingPublish = 0;
        $missingContract = 0;

        Page::query()
            ->where('site_id', $invocation->siteId)
            ->select(['id', 'blueprint_id', 'site_id'])
            ->chunkById(200, function (Collection $pages) use (&$pagesChecked, &$completePages, &$missingPublish, &$missingContract): void {
                foreach ($pages as $page) {
                    if (! $page instanceof Page) {
                        continue;
                    }

                    $pagesChecked++;
                    $completeness = EvaluatePropertyCompletenessAction::run($page);
                    $missingPublish += count($completeness->missingPublishRequired);
                    $missingContract += count($completeness->missingContractRequired);

                    if ($completeness->isAgentComplete()) {
                        $completePages++;
                    }
                }
            });

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'site_id' => $invocation->siteId,
                'pages_checked' => $pagesChecked,
                'complete_pages' => $completePages,
                'incomplete_pages' => $pagesChecked - $completePages,
                'missing_publish_required' => $missingPublish,
                'missing_contract_required' => $missingContract,
            ],
        );
    }
}
