<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorLockRequestData;
use Capell\Admin\Data\Pages\PageEditorScratchDraftInputData;
use Capell\Admin\Data\Pages\PageEditorScratchDraftResultData;
use Capell\Admin\Enums\PageEditorLockOperation;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SavePageEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    public function handle(PageEditorScratchDraftInputData $input): PageEditorScratchDraftResultData
    {
        if (! $input->user instanceof Authenticatable) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthenticated);
        }

        if (Gate::forUser($input->user)->denies('update', $input->page)) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthorized);
        }

        return $input->page->getConnection()->transaction(function () use ($input): PageEditorScratchDraftResultData {
            SiteAccess::forActor($input->user)->query($input->page::class)->whereKey($input->page->getKey())->lockForUpdate()->firstOrFail();

            return $this->saveLocked($input);
        });
    }

    private function saveLocked(PageEditorScratchDraftInputData $input): PageEditorScratchDraftResultData
    {
        if (! $input->user instanceof Authenticatable) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthenticated);
        }

        $lock = ResolvePageEditorLockAction::run(new PageEditorLockRequestData(
            record: $input->page,
            user: $input->user,
            operation: PageEditorLockOperation::Inspect,
        ));

        if ($lock->isBlocked()) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Locked);
        }

        if ($input->context === 'page-editor') {
            $draft = SaveEditorScratchDraftAction::run(
                record: $input->page,
                user: $input->user,
                locale: $input->locale,
                context: $input->context,
                payload: $input->payload,
            );

            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Saved, 1, $draft->revision);
        }

        $identity = [
            'user_id' => $input->user->getAuthIdentifier(),
            'site_id' => $input->page->site_id,
            'locale' => $input->locale,
            'record_type' => $input->page->getMorphClass(),
            'record_id' => $input->page->getKey(),
            'context' => $input->context,
        ];
        $payload = json_encode($input->payload, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $payload);
        $revision = (string) Str::uuid();
        $now = CarbonImmutable::now('UTC');
        $values = [
            'payload' => $payload,
            'content_hash' => $hash,
            'revision' => $revision,
            'previous_revision' => $input->expectedRevision,
            'saved_at' => $now,
            'expires_at' => $now->addMinutes(SaveEditorScratchDraftAction::DEFAULT_TTL_MINUTES),
            'updated_at' => $now,
        ];
        if ($input->expectedRevision === null) {
            $affected = SiteAccess::forActor($input->user)->query(EditorScratchDraft::class)->insertOrIgnore([...$identity, ...$values, 'created_at' => $now]);
        } else {
            $affected = SiteAccess::forActor($input->user)->query(EditorScratchDraft::class)->where($identity)->where('revision', $input->expectedRevision)->update($values);
        }

        if ($affected === 0) {
            $committed = SiteAccess::forActor($input->user)->query(EditorScratchDraft::class)->where($identity)
                ->where('previous_revision', $input->expectedRevision)->where('content_hash', $hash)->first();
            if ($committed instanceof EditorScratchDraft && $committed->revision !== null) {
                return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Saved, 0, $committed->revision);
            }
        }

        return new PageEditorScratchDraftResultData(
            status: $affected === 1 ? PageEditorScratchDraftStatus::Saved : PageEditorScratchDraftStatus::Conflict,
            affectedRows: $affected,
            revision: $affected === 1 ? $revision : null,
        );
    }
}
