<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Extenders\PageEditExtender;
use Filament\Actions\Action;

final readonly class AgentPageReadinessExtender implements PageEditExtender
{
    /** @return list<Action> */
    public function getFormActions(): array
    {
        return [];
    }

    /** @return list<class-string<AgentPageReadinessWidget>> */
    public function getHeaderWidgets(): array
    {
        return [AgentPageReadinessWidget::class];
    }
}
