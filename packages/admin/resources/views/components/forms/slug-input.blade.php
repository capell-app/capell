@php
    $statePath = $getStatePath();
    $relativeStatePath = $getStatePath(false);
    $wireModelAttribute = $applyStateBindingModifiers('wire:model');
    $recordUrl = $getRecordUrl();
@endphp

@once
    {{--
        The page title/slug FusedGroup renders a hidden `slug_auto_update_disabled`
        field as its real last child, so Filament's "divider on every row except
        the last" rule still paints a bottom border on the visible slug row. That
        square 1px line clashes with the group's rounded bottom corners. Drop it
        from the last visible row (the one immediately followed by the hidden field).
    --}}
    <style>
        .page-title-with-slug-input
            .fi-sc.fi-grid
            > .fi-grid-col:has(+ .fi-grid-col.fi-hidden) {
            border-bottom-width: 0;
        }
    </style>
@endonce

<div
    id="{{ $getId() }}-permalink"
    class="filament-seo-slug-input-wrapper px-2 py-1.5"
>
    <div
        x-data="{
            context: @js($getContext()),
            initialState: @js($getState()),
            fullBaseUrl: @js($getFullBaseUrl()),
            rootBaseUrl: @js($getDisplayBaseUrl('/')),
            basePath: @js($getBasePath()),
            editing: false,
            modified: false,
            init() {
                this.$wire.watch('{{ $statePath }}', (value) => {
                    this.modified = value !== this.initialState
                })

                $wire.$hook('commit', ({ commit, succeed }) => {
                    succeed(() => {
                        if (commit.calls[0]?.method !== 'save') {
                            return
                        }
                        this.initialState = this.$get('{{ $relativeStatePath }}')
                        this.modified = false
                    })
                })
            },
            initModification() {
                this.editing = true
                this.$nextTick(() => this.$refs.slugInput?.focus())
            },
            submitModification() {
                let state = $get('{{ $relativeStatePath }}')
                let slug = state
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                this.modified = slug !== this.initialState
                this.editing = false
            },
            cancelModification() {
                this.editing = false
            },
            resetModification() {
                this.$set('{{ $relativeStatePath }}', this.initialState)
                this.modified = false
            },
            currentSlug() {
                return $get('{{ $relativeStatePath }}') ?? ''
            },
            displayBaseUrl() {
                return this.currentSlug() === '/' ? this.rootBaseUrl : this.fullBaseUrl
            },
        }"
    >
        <div
            {{ $attributes->merge($getExtraAttributes())->class(['filament-forms-text-input-component group flex min-w-0 items-center text-sm']) }}
        >
            @if ($getReadOnly())
                <span class="flex min-w-0 items-center">
                    <span class="mr-1">{{ $getLabelPrefix() }}</span>
                    <span class="text-gray-400">
                        {{ $getDisplayBaseUrl($getState()) }}
                    </span>
                    <span class="font-semibold text-gray-400">
                        {{ $getState() }}
                    </span>
                </span>
            @else
                <span
                    class="flex min-w-0 items-center"
                    x-show="!editing"
                    x-cloak
                >
                    <span>{{ $getLabelPrefix() }}</span>

                    @if ($recordUrl)
                        <a
                            href="{{ $recordUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="page-title-with-slug-link hover:text-primary-600 hover:decoration-primary-500 focus-visible:ring-primary-500 dark:hover:text-primary-400 ml-1 inline-flex min-w-0 items-baseline text-gray-400 no-underline transition focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span
                                class="shrink-0"
                                x-text="displayBaseUrl()"
                            ></span>
                            <span
                                class="truncate font-semibold text-gray-600 underline decoration-gray-300 underline-offset-2 dark:text-gray-300"
                                x-text="currentSlug()"
                            ></span>
                            <span class="sr-only">
                                {{ $getVisitLinkLabel() }}
                            </span>
                        </a>
                    @else
                        <span
                            class="ml-1 inline-flex min-w-0 items-baseline text-gray-400"
                        >
                            <span
                                class="shrink-0"
                                x-text="displayBaseUrl()"
                            ></span>
                            <span
                                class="truncate font-semibold text-gray-600 dark:text-gray-300"
                                x-text="currentSlug()"
                            ></span>
                        </span>
                    @endif

                    <button
                        type="button"
                        x-on:click.prevent="initModification()"
                        class="page-title-with-slug-edit-slug hover:border-primary-300 hover:text-primary-600 focus-visible:ring-primary-500 dark:hover:border-primary-600 dark:hover:text-primary-400 ml-2 inline-flex shrink-0 cursor-pointer items-center rounded-sm border border-gray-300 bg-white px-1.5 py-0.5 text-xs leading-none font-medium text-gray-600 transition focus-visible:ring-2 focus-visible:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <span>
                            {{ __('capell-admin::generic.permalink_action_edit') }}
                        </span>
                    </button>

                    @if ($getSlugLabelPostfix())
                        <span class="ml-0.5 text-gray-400">
                            {{ $getSlugLabelPostfix() }}
                        </span>
                    @endif

                    <span
                        class="ml-2 text-xs font-medium tracking-tight"
                        x-show="context !== 'create' && modified"
                        x-cloak
                    >
                        [{{ __('capell-admin::generic.permalink_status_changed') }}]
                    </span>
                </span>

                <div
                    class="flex min-w-0 flex-1 items-center gap-2"
                    x-show="editing"
                    x-cloak
                >
                    <span
                        x-text="basePath"
                        class="shrink-0 text-gray-400"
                    ></span>

                    <div class="fi-input-wrp">
                        <div class="fi-input-wrp-content-ctn">
                            <input
                                type="text"
                                x-ref="slugInput"
                                x-bind:disabled="!editing"
                                x-on:keydown.enter="submitModification()"
                                x-on:keydown.escape="cancelModification()"
                                {!! ($autocomplete = $getAutocomplete()) ? "autocomplete=\"{$autocomplete}\"" : null !!}
                                id="{{ $getId() }}"
                                {{ $wireModelAttribute }}="{{ $statePath }}"
                                {!! ($placeholder = $getPlaceholder()) ? "placeholder=\"{$placeholder}\"" : null !!}
                                {!! $isRequired() ? 'required' : null !!}
                                {{
                                    $getExtraInputAttributeBag()->class([
                                        'fi-input text-sm font-semibold',
                                        'border-danger-600 ring-danger-600' => $errors->has($statePath)])
                                }}
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="ml-2 flex items-center gap-1.5"
                    x-show="editing"
                    x-cloak
                >
                    <x-filament::button
                        x-on:click.prevent="submitModification()"
                    >
                        {{ __('capell-admin::generic.permalink_action_ok') }}
                    </x-filament::button>

                    <x-filament::icon-button
                        icon="heroicon-o-arrow-path"
                        size="sm"
                        color="info"
                        x-show="context === 'edit' && modified"
                        x-cloak
                        x-on:click.prevent="resetModification()"
                        :label="__('capell-admin::generic.permalink_action_reset')"
                        :tooltip="__('capell-admin::generic.permalink_action_reset')"
                    />

                    <x-filament::icon-button
                        icon="heroicon-o-x-mark"
                        size="sm"
                        color="danger"
                        x-on:click.prevent="cancelModification()"
                        :label="__('capell-admin::generic.permalink_action_cancel')"
                        :tooltip="__('capell-admin::generic.permalink_action_cancel')"
                    />
                </div>
            @endif
        </div>

        @error($statePath)
            <p class="text-danger-600 dark:text-danger-400 mt-1 text-sm">
                {{ $message }}
            </p>
        @enderror
    </div>
</div>
