<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire;

use Capell\Admin\Actions\DismissHintAction;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InfoBanner extends Component
{
    #[Locked]
    public string $hintKey = '';

    #[Locked]
    public string $tone = 'info';

    public bool $visible = true;

    public function mount(): void
    {
        $userId = Auth::id();

        if ($userId === null || $this->hintKey === '') {
            $this->visible = false;

            return;
        }

        $raw = DB::table('users')->where('id', $userId)->value('dismissed_hints');
        $dismissed = is_string($raw) ? JsonCodec::decodeArray($raw) : [];

        if (in_array($this->hintKey, $dismissed, true)) {
            $this->visible = false;
        }
    }

    public function dismiss(): void
    {
        $user = Auth::user();

        if (! $user instanceof AuthenticatableUser) {
            return;
        }

        DismissHintAction::run($user, $this->hintKey);

        $this->visible = false;
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'capell-admin::livewire.info-banner';

        return view($view, [
            'content' => $this->visible ? __('capell-admin::hints.' . $this->hintKey) : '',
        ]);
    }
}
