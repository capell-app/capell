<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Livewire;

use Capell\Core\Actions\EditorScratchDrafts\DiscardEditorScratchDraftAction;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
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

    public function mount(int $pageId, ?string $locale = null): void
    {
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

        return EditorScratchDraft::query()
            ->forEditor($user, $page, $this->locale, self::CONTEXT)
            ->where('expires_at', '>', CarbonImmutable::now('UTC'))
            ->first();
    }

    public function restore(): void
    {
        $draft = $this->draft;

        if (! $draft instanceof EditorScratchDraft) {
            return;
        }

        $this->dispatch(
            'page-scratch-draft-restored',
            pageId: $this->pageId,
            data: $draft->payload,
        );
    }

    public function discard(): void
    {
        $user = auth()->user();
        $page = $this->page();

        if (! $user instanceof Authenticatable || ! $page instanceof Page || ! Gate::forUser($user)->allows('update', $page)) {
            return;
        }

        DiscardEditorScratchDraftAction::run($page, $user, $this->locale, self::CONTEXT);
        unset($this->draft);

        Notification::make('editor-scratch-draft-discarded')
            ->success()
            ->title(__('capell-admin::scratch_drafts.discarded'))
            ->send();
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
        return Page::query()->find($this->pageId);
    }
}
