<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Livewire;

use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @property-read ?EditorScratchDraft $draft
 */
final class PageScratchDraftPanel extends Component
{
    private const string CONTEXT = 'page-editor';

    #[Locked]
    public int $pageId;

    #[Locked]
    public string $locale;

    #[Locked]
    public ?string $currentContext = null;

    public function mount(int $pageId, ?string $locale = null, ?string $currentContext = null): void
    {
        $this->currentContext = $currentContext;
        $this->pageId = $pageId;
        $this->locale = $locale ?? app()->getLocale();
    }

    #[Computed]
    public function draft(): ?EditorScratchDraft
    {
        $user = auth()->user();
        $page = $this->page();

        if (! $user instanceof Authenticatable || ! $page instanceof Page || ! Gate::forUser($user)->allows('update', $page)) {
            return null;
        }

        return SiteAccess::current()->query(EditorScratchDraft::class)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('site_id', $page->site_id)
            ->where('record_type', $page->getMorphClass())
            ->where('record_id', $page->getKey())
            ->where('locale', $this->locale)
            ->where(fn (Builder $query) => $query->where('context', self::CONTEXT)->orWhere('context', 'like', self::CONTEXT . ':%'))
            ->when($this->currentContext !== null, fn (Builder $query) => $query->where('context', '!=', $this->currentContext))
            ->where('expires_at', '>', CarbonImmutable::now('UTC'))
            ->oldest('saved_at')->orderBy('id')
            ->first();
    }

    public function restore(?int $draftId = null, ?string $revision = null, ?string $contentHash = null): void
    {
        $draft = $this->draft;
        $revision = $revision === '' ? null : $revision;

        if (! $draft instanceof EditorScratchDraft || ($draftId !== null && ($draft->id !== $draftId || $draft->revision !== $revision || $draft->content_hash !== $contentHash))) {
            if ($draftId !== null) {
                $this->notifyRecoveryChanged();
            }

            return;
        }

        $this->dispatch(
            'page-scratch-draft-restored',
            pageId: $this->pageId,
            data: $draft->payload,
            draftId: $draft->id,
            revision: $draft->revision,
            contentHash: $draft->content_hash,
        );
    }

    public function discard(?int $draftId = null, ?string $revision = null, ?string $contentHash = null): void
    {
        $user = auth()->user();
        $page = $this->page();

        if (! $user instanceof Authenticatable || ! $page instanceof Page || ! Gate::forUser($user)->allows('update', $page)) {
            return;
        }

        $draft = $this->draft;
        $revision = $revision === '' ? null : $revision;
        if (! $draft instanceof EditorScratchDraft || ($draftId !== null && ($draft->id !== $draftId || $draft->revision !== $revision || $draft->content_hash !== $contentHash))) {
            if ($draftId !== null) {
                $this->notifyRecoveryChanged();
            }

            return;
        }

        $deleted = $page->getConnection()->transaction(function () use ($draft, $page): int {
            SiteAccess::current()->query($page::class)->whereKey($page->getKey())->lockForUpdate()->firstOrFail();

            return SiteAccess::current()->query(EditorScratchDraft::class)->whereKey($draft->id)->where('revision', $draft->revision)->where('content_hash', $draft->content_hash)->delete();
        });
        unset($this->draft);

        if ($deleted !== 1) {
            $this->notifyRecoveryChanged();

            return;
        }

        Notification::make('editor-scratch-draft-discarded')
            ->success()
            ->title(__('capell-admin::scratch_drafts.discarded'))
            ->send();
    }

    #[On('page-scratch-draft-conflict')]
    public function showCurrentRecovery(int $pageId, ?string $currentContext = null): void
    {
        if ($pageId === $this->pageId) {
            $this->currentContext = $currentContext;
            unset($this->draft);
        }
    }

    #[On('page-scratch-draft-updated')]
    public function refreshDraft(int $pageId): void
    {
        if ($pageId === $this->pageId) {
            unset($this->draft);
        }
    }

    public function render(): View
    {
        return view('capell-admin::livewire.page-scratch-draft-panel');
    }

    private function page(): ?Page
    {
        return SiteAccess::current()->query(Page::class)->find($this->pageId);
    }

    private function notifyRecoveryChanged(): void
    {
        unset($this->draft);

        Notification::make('editor-scratch-draft-changed')
            ->warning()
            ->title(__('capell-admin::scratch_drafts.recovery_changed'))
            ->send();
    }
}
