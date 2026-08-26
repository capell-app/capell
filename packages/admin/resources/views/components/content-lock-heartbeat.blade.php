@php
    use Filament\Support\Facades\FilamentAsset;

    /**
     * @var array{
     *     heartbeatUrl: string,
     *     releaseUrl: string,
     *     logoutUrl: string,
     *     csrfToken: string,
     *     intervalMs: int,
     *     initialConflict: bool,
     *     pageId: int,
     *     storageKey: string,
     *     formSelector: string,
     *     localDraftDebounceMs: int,
     *     localDraftTtlMs: int,
     *     localDraftVersion: int,
     *     localDraftAvailableMessage: string,
     *     localDraftRestoreLabel: string,
     *     localDraftDiscardLabel: string,
     *     contentLockReadOnlyMessage: string,
     *     contentLockPermissionMessage: string,
     *     contentLockUnavailableMessage: string,
     *     contentLockTakeoverLabel: string,
     * } $config
     */
@endphp

<div
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('capell-content-lock-heartbeat', 'capell-admin') }}"
    x-data="capellContentLockHeartbeat(@js($config))"
    x-init="init()"
    x-on:content-lock-taken-over.window="if (Number($event.detail.pageId) === {{ $config['pageId'] }}) clearConflict()"
    x-on:page-editor-saved.window="if (Number($event.detail.pageId) === {{ $config['pageId'] }}) markEditorSaved()"
    data-capell-content-lock-heartbeat
    class="flex flex-col gap-3"
>
    <div
        x-cloak
        x-show="localDraftAvailable"
        class="rounded-lg bg-primary-50 p-4 text-sm text-primary-950 ring-1 ring-primary-200 dark:bg-primary-950/30 dark:text-primary-100 dark:ring-primary-800"
        role="status"
        aria-live="polite"
    >
        <p x-text="localDraftAvailableMessage"></p>
        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                class="fi-btn fi-btn-size-md fi-color-primary"
                x-bind:disabled="readOnly"
                x-on:click="restoreLocalDraft()"
                x-text="localDraftRestoreLabel"
            ></button>
            <button
                type="button"
                class="fi-btn fi-btn-size-md fi-color-gray"
                x-on:click="discardLocalDraft()"
                x-text="localDraftDiscardLabel"
            ></button>
        </div>
    </div>

    <div
        x-cloak
        x-show="readOnly"
        class="rounded-lg bg-warning-50 p-4 text-sm text-warning-950 ring-1 ring-warning-200 dark:bg-warning-950/30 dark:text-warning-100 dark:ring-warning-800"
        role="alert"
        aria-live="assertive"
    >
        <p
            x-show="heartbeatUnavailable"
            x-text="contentLockUnavailableMessage"
        ></p>
        <p
            x-show="permissionBlocked"
            x-text="contentLockPermissionMessage"
        ></p>
        <p
            x-show="!heartbeatUnavailable && !permissionBlocked"
            x-text="contentLockReadOnlyMessage"
        ></p>
        <button
            x-cloak
            x-show="canRequestTakeover"
            type="button"
            class="fi-btn fi-btn-size-md fi-color-warning mt-3"
            x-on:click="requestTakeover()"
            x-text="contentLockTakeoverLabel"
        ></button>
    </div>
</div>
