<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Data\Workflow\WorkflowAttentionItemData;
use Illuminate\Contracts\Auth\Authenticatable;

final class TestPublishingWorkflowAttentionContribution implements ContributesWorkflowAttention
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }

    /**
     * @return list<WorkflowAttentionItemData>
     */
    public function attentionItems(?Authenticatable $user = null): array
    {
        return [
            new WorkflowAttentionItemData(
                packageName: 'capell-app/publishing-studio',
                label: 'Awaiting review',
                severity: 'warning',
                owner: 'Publishing Studio',
                nextActionLabel: 'Open Publishing Studio',
                url: '/admin/publishing-studio/workflow',
                permission: 'View:PublishingWorkflowPage',
                count: 3,
            ),
        ];
    }
}
