<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Pages;

use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class PageCreationRecovery
{
    /** @param array<string, mixed> $data */
    public function save(?Authenticatable $user, int $siteId, string $locale, string $context, ?string $expectedRevision, array $data): ?string
    {
        if (! $this->allowed($user, $siteId) || ! $user instanceof Authenticatable) {
            return null;
        }

        $access = SiteAccess::forActor($user);

        return $access->query(Site::class)->getConnection()->transaction(function () use ($access, $user, $siteId, $locale, $context, $expectedRevision, $data): ?string {
            $site = $access->query(Site::class)->whereKey($siteId)->lockForUpdate()->first();
            if (! $site instanceof Site || ! $this->allowed($user, $siteId)) {
                return null;
            }

            $identity = ['user_id' => $user->getAuthIdentifier(), 'site_id' => $siteId, 'locale' => $locale,
                'record_type' => (new Page)->getMorphClass(), 'record_id' => 0, 'context' => $context];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            $now = CarbonImmutable::now('UTC');
            $revision = (string) Str::uuid();
            $values = ['payload' => $encoded, 'content_hash' => hash('sha256', $encoded), 'revision' => $revision, 'previous_revision' => $expectedRevision,
                'saved_at' => $now, 'expires_at' => $now->addMinutes(SaveEditorScratchDraftAction::DEFAULT_TTL_MINUTES), 'updated_at' => $now];
            $affected = $expectedRevision === null
                ? $access->query(EditorScratchDraft::class)->insertOrIgnore([...$identity, ...$values, 'created_at' => $now])
                : $access->query(EditorScratchDraft::class)->where($identity)->where('revision', $expectedRevision)->update($values);

            if ($affected === 1) {
                return $revision;
            }

            $committed = $access->query(EditorScratchDraft::class)->where($identity)
                ->where('previous_revision', $expectedRevision)->where('content_hash', hash('sha256', $encoded))->first();

            return $committed?->revision;
        });
    }

    public function find(?Authenticatable $user, int $siteId, string $locale, ?string $currentContext): ?EditorScratchDraft
    {
        if (! $this->allowed($user, $siteId) || ! $user instanceof Authenticatable) {
            return null;
        }

        return $this->drafts($user, $siteId, $locale)->when($currentContext !== null, fn (Builder $query): Builder => $query->where('context', '!=', $currentContext))
            ->where('expires_at', '>', CarbonImmutable::now('UTC'))->oldest('saved_at')->orderBy('id')->first();
    }

    public function discard(?Authenticatable $user, int $siteId, string $locale, int $id, ?string $revision, string $hash): bool
    {
        if (! $this->allowed($user, $siteId) || ! $user instanceof Authenticatable) {
            return false;
        }

        return $this->drafts($user, $siteId, $locale)->whereKey($id)->where('revision', $revision)->where('content_hash', $hash)->delete() === 1;
    }

    public function discardSession(?Authenticatable $user, int $siteId, string $locale, string $context, ?string $revision): void
    {
        if ($revision === null || ! $this->allowed($user, $siteId) || ! $user instanceof Authenticatable) {
            return;
        }

        $this->drafts($user, $siteId, $locale)->where('context', $context)->where('revision', $revision)->delete();
    }

    private function allowed(?Authenticatable $user, int $siteId): bool
    {
        return $user instanceof Authenticatable
            && Gate::forUser($user)->allows('create', Page::class)
            && SiteAccess::forActor($user)->site($siteId, false) instanceof Site;
    }

    /** @return Builder<EditorScratchDraft> */
    private function drafts(Authenticatable $user, int $siteId, string $locale): Builder
    {
        return SiteAccess::forActor($user)->query(EditorScratchDraft::class)->where('user_id', $user->getAuthIdentifier())
            ->where('site_id', $siteId)->where('locale', $locale)
            ->where('record_type', (new Page)->getMorphClass())->where('record_id', 0)
            ->where('context', 'like', 'page-create:%');
    }
}
