<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Publish;

use Capell\Admin\Contracts\Extenders\PublishPanelExtender;
use Capell\Admin\Data\PagePublishStateData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageWorkflowState;
use Illuminate\Contracts\View\View;

/**
 * Enriches the publish panel with the editorial workflow status projected from
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
    public function extendPanel(PagePublishStateData $state): ?View
    {
        $page = Page::query()->find($state->pageId);

        if ($page === null) {
            return null;
        }

        $workflow = PageWorkflowState::query()
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
}
