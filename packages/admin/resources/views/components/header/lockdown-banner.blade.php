@php
    use Capell\Core\Support\Security\LockdownStore;

    $lockdown = resolve(LockdownStore::class);
@endphp

<div
    x-data="{ active: @js($lockdown->active()) }"
    x-show="active"
    x-cloak
    x-on:capell-lockdown-state-changed.window="active = $event.detail.active"
>
    <div
        class="border-b border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-800 dark:border-red-800 dark:bg-red-950/50 dark:text-red-200"
        role="status"
        aria-live="polite"
    >
        {{ __('capell-admin::message.lockdown_banner') }}
    </div>
</div>
