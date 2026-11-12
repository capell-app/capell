<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire\Header;

use Capell\Core\Support\Permissions\SiteAccess;
use CmsMulti\FilamentClearCache\Http\Livewire\ClearCache;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Override;

/**
 * The admin header's clear-cache button. Clearing runs the application cache clear and Capell's admin cache
 * clear, which reaches every site's HTML cache, so only a global administrator may see or use it. Each Livewire
 * call is checked on the server; hiding the button is not the guard.
 */
final class ClearCacheButton extends ClearCache
{
    #[Override]
    public function getVisibility(): bool
    {
        return $this->actorIsGlobalAdministrator();
    }

    #[Override]
    public function clear(): void
    {
        $this->ensureGlobalAdministrator();

        parent::clear();
    }

    /** Only a global administrator's badge counts pending changes; anyone else's call changes nothing. */
    #[Override]
    public function incrementChangesCount(): void
    {
        if ($this->actorIsGlobalAdministrator()) {
            parent::incrementChangesCount();
        }
    }

    #[Override]
    public function render(): View
    {
        // The vendor button ignores its visibility, so anyone else gets an empty placeholder.
        return $this->visible ? parent::render() : view('capell-admin::livewire.header.clear-cache-button-hidden');
    }

    private function ensureGlobalAdministrator(): void
    {
        throw_unless($this->actorIsGlobalAdministrator(), AuthorizationException::class, __('capell-admin::message.clear_cache_requires_global_admin'));
    }

    private function actorIsGlobalAdministrator(): bool
    {
        $actor = auth()->user();

        return $actor !== null && SiteAccess::forActor($actor)->isGlobal();
    }
}
