<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorScratchDraftResultData;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Actions\EditorScratchDrafts\DiscardEditorScratchDraftAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DiscardPageEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Model&Pageable<Model>  $page
     */
    public function handle(
        Model&Pageable $page,
        ?Authenticatable $user,
        string $locale,
        string $context = 'page-editor',
        ?string $expectedRevision = null,
    ): PageEditorScratchDraftResultData {
        if (! $user instanceof Authenticatable) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthenticated);
        }

        $deleted = $page->getConnection()->transaction(function () use ($page, $user, $locale, $context, $expectedRevision): int {
            SiteAccess::forActor($user)->scope($page->newQueryWithoutScopes())->whereKey($page->getKey())->lockForUpdate()->firstOrFail();

            return $context === 'page-editor'
                ? DiscardEditorScratchDraftAction::run($page, $user, $locale, $context)
                : ($expectedRevision === null ? 0 : SiteAccess::forActor($user)->query(EditorScratchDraft::class)
                    ->forEditor($user, $page, $locale, $context)
                    ->where('revision', $expectedRevision)
                    ->delete());
        });

        return new PageEditorScratchDraftResultData(
            status: PageEditorScratchDraftStatus::Discarded,
            affectedRows: $deleted,
        );
    }
}
