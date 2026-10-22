<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Publish;

use Capell\Admin\Contracts\Extenders\PublishPanelExtender;
use Capell\Admin\Data\PagePublishStateData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\View\View;
use Override;

/**
 * Enriches the publish panel with the saved workspace draft status projected from
 * the event stream (who is reviewing, who approved) — intent the inferred
 * publish state cannot express.
 *
 * Renders the status badge today; interactive transition buttons (Submit /
 * Approve / Request changes / Schedule / Publish) require the publish panel to
 * be hosted by a Livewire component exposing transition methods, which is not
 * yet wired in this codebase. This extender is registered and ready for that
 * host.
 */
final class WorkflowPublishPanelExtender implements PublishPanelExtender
{
    #[Override]
    public function extendPanel(PagePublishStateData $state): ?View
    {
        $page = SiteAccess::current()->query(Page::class)->find($state->pageId);

        if ($page === null) {
            return null;
        }

        $handler = app()->bound('capell.workspace.page-draft-handler')
            ? resolve('capell.workspace.page-draft-handler')
            : null;
        $hasWorkspaceDraft = $this->hasWorkspaceDraft($handler, $page);

        if (! $hasWorkspaceDraft) {
            return null;
        }

        $workflow = SiteAccess::current()->query(PageWorkflowState::class)
            ->where('page_uuid', $page->uuid)
            ->first();

        if ($workflow === null) {
            return null;
        }

        return view('capell-admin::event-sourcing.workflow-panel', [
            'status' => $workflow->status,
            'approverId' => $workflow->approver_id,
            'note' => $workflow->requested_changes_note,
        ]);
    }

    /**
     * Prefer the handler's lifecycle-aware predicate. A handler from an older
     * Publishing Studio only counts retained draft rows, which still answers
     * "is there a draft" correctly until it is updated (it may keep showing the
     * row for an abandoned workspace, as before). Without a handler, only the
     * record's own workspace marks a draft.
     */
    private function hasWorkspaceDraft(mixed $handler, Page $page): bool
    {
        if (is_object($handler) && is_callable([$handler, 'hasActiveDraft'])) {
            return (bool) $handler->hasActiveDraft($page);
        }

        if (is_object($handler) && is_callable([$handler, 'countDrafts'])) {
            return (int) $handler->countDrafts($page) > 0;
        }

        return (int) ($page->getAttributes()['workspace_id'] ?? 0) > 0;
    }
}
