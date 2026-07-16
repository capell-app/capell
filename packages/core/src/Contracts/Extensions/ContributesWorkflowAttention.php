<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Data\Workflow\WorkflowAttentionItemData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Supplies extension-owned items that need attention in publishing workflow.
 *
 * Register the implementation as a workflow-attention manifest contribution.
 * Admin resolves it while building the current actor's publishing workflow
 * entry; failures are treated as an unavailable optional contribution.
 */
interface ContributesWorkflowAttention extends ExtensionContribution
{
    /**
     * Return the items visible to the supplied actor.
     *
     * @return list<WorkflowAttentionItemData>
     */
    public function attentionItems(?Authenticatable $user = null): array;
}
