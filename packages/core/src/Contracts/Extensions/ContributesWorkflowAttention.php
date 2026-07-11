<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Data\Workflow\WorkflowAttentionItemData;
use Illuminate\Contracts\Auth\Authenticatable;

interface ContributesWorkflowAttention extends ExtensionContribution
{
    /**
     * @return list<WorkflowAttentionItemData>
     */
    public function attentionItems(?Authenticatable $user = null): array;
}
