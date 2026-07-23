<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire\Header;

use Capell\Admin\Actions\ActivateLockdownAction;
use Capell\Admin\Actions\Cache\QueueFrontendBuildAction;
use Capell\Admin\Actions\DeactivateLockdownAction;
use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\Admin\Enums\ListenerEnum;
use Capell\Admin\Support\AdminTools\AdminToolRegistry;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\Security\LockdownStore;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class AdminTools extends Component
{
    public bool $lockdownActive = false;

    protected string $view = 'capell-admin::livewire.header.admin-tools';

    public function mount(): void
    {
        // Actions enforce assertGlobalAdmin() individually; mount must not throw
        // or it 403s the whole page render for non-super-admin users.
        $this->refreshLockdownState();
    }

    public function rebuildSite(): void
    {
        $this->assertGlobalAdmin();

        if ($this->siteTree()) {
            Notification::make('page_tree')
                ->status('warning')
                ->title(__('capell-admin::message.fixed_page_tree'))
                ->send();

            return;
        }

        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::SiteTreeRebuilt, $this);

        Notification::make('success')
            ->title(__('capell-admin::message.rebuild_site_no_errors'))
            ->send();

        $this->successResponse(
            name: 'rebuild_site',
            title: __('capell-admin::generic.site_rebuilt'),
            body: __('capell-admin::message.rebuild_site_no_errors'),
        );
    }

    public function buildFrontend(): void
    {
        $this->assertGlobalAdmin();

        try {
            $result = QueueFrontendBuildAction::run();
        } catch (QueueConnectionNotReadyException $queueConnectionNotReadyException) {
            $this->successResponse(
                name: 'build_frontend',
                title: __('capell-admin::message.frontend_build_queue_unavailable'),
                body: $queueConnectionNotReadyException->getMessage(),
                type: 'danger',
            );

            return;
        }

        $this->successResponse(
            name: 'build_frontend',
            title: $result->getLabel(),
            body: __('capell-admin::message.frontend_build_queued_body'),
            type: $result->queued() ? 'success' : 'warning',
        );
    }

    public function clearCache(): void
    {
        $this->assertGlobalAdmin();

        Artisan::call('capell:admin-clear-cache');

        $this->successResponse(
            name: 'clear_cache',
            title: __('capell-admin::message.page_cache_deleted'),
        );
    }

    public function enableLockdown(): void
    {
        $this->assertGlobalAdmin();

        $user = Filament::auth()->user();
        throw_if(! $user instanceof Authenticatable, AuthenticationException::class);

        ActivateLockdownAction::run($user);
        $this->refreshLockdownState();
        $this->dispatch('capell-lockdown-state-changed', active: true);

        $this->successResponse(
            name: 'lockdown_enabled',
            title: __('capell-admin::message.lockdown_enabled'),
            type: 'danger',
        );
    }

    public function disableLockdown(): void
    {
        $this->assertGlobalAdmin();

        DeactivateLockdownAction::run();
        $this->refreshLockdownState();
        $this->dispatch('capell-lockdown-state-changed', active: false);

        $this->successResponse(
            name: 'lockdown_disabled',
            title: __('capell-admin::message.lockdown_disabled'),
        );
    }

    /** @return iterable<AdminToolItem> */
    public function tools(): iterable
    {
        return resolve(AdminToolRegistry::class)->all();
    }

    public function canViewTools(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $this->isGlobalAdmin($user);
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = $this->view;

        return view($view);
    }

    public function siteTree(): bool
    {
        /** @var class-string<Page> $model */
        $model = Page::class;

        if (! $model::isBrokenWithoutPublish()) {
            return false;
        }

        $model::fixTree();

        return true;
    }

    public function refreshLockdownState(): void
    {
        $this->lockdownActive = resolve(LockdownStore::class)->active();
    }

    private function successResponse(string $name, string $title, ?string $body = null, string $type = 'success'): void
    {
        Notification::make($name)
            ->status($type)
            ->title($title)
            ->body($body)
            ->send();

        $this->dispatch('close-dropdown', id: 'admin-tools-dropdown');
    }

    /**
     * Every action on this component performs a global, site-wide write
     * (rebuild tree, shell-out to npm build, regenerate static sites / sitemaps).
     * These are super-admin only.
     * Filament only renders the dropdown for global admins, but the
     * Livewire endpoint must re-enforce the check on every request.
     */
    private function assertGlobalAdmin(): void
    {
        $user = Filament::auth()->user();

        throw_if($user === null, AuthenticationException::class);

        throw_unless(resolve(LockdownStore::class)->canAccessAdmin($user), AuthorizationException::class);

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        throw new AuthorizationException;
    }

    private function isGlobalAdmin(object $user): bool
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        if (method_exists($user, 'hasRole')) {
            return $user->hasRole($superAdminRole);
        }

        return method_exists($user, 'isGlobalAdmin') && $user->isGlobalAdmin();
    }
}
