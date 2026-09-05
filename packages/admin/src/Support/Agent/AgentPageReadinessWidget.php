<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Models\Page;
use Illuminate\Support\Collection;

final class AgentPageReadinessWidget extends ResourceAlertsFilamentWidget
{
    public ?Page $record = null;

    /** @return Collection<string, MessageData> */
    protected function buildAlerts(): Collection
    {
        $alerts = collect();

        if (! $this->record instanceof Page) {
            return $alerts;
        }

        $completeness = EvaluatePropertyCompletenessAction::run($this->record);

        if ($completeness->isAgentComplete()) {
            return $alerts;
        }

        $missing = array_values(array_unique([
            ...$completeness->missingPublishRequired,
            ...$completeness->missingContractRequired,
        ]));

        $alerts->put('agentReadiness', new MessageData(
            title: __('capell-admin::agent.readiness_incomplete_title'),
            message: __('capell-admin::agent.readiness_incomplete_message', [
                'properties' => implode(', ', $missing),
            ]),
            type: AlertTypeEnum::Warning,
            icon: 'heroicon-o-sparkles',
        ));

        return $alerts;
    }
}
