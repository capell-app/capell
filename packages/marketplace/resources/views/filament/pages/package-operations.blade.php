<x-filament-panels::page>
    @php
        $selectedOperation = $this->selectedOperation();
    @endphp

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-normal text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.operations.page_title') }}
                </h1>
                <p
                    class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400"
                >
                    {{ __('capell-marketplace::marketplace.operations.page_description') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-filament::button
                    tag="a"
                    color="gray"
                    href="{{ $this->extensionsUrl() }}"
                    icon="heroicon-o-puzzle-piece"
                >
                    {{ __('capell-marketplace::marketplace.operations.extensions') }}
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    color="gray"
                    href="{{ $this->marketplaceUrl() }}"
                    icon="heroicon-o-shopping-bag"
                >
                    {{ __('capell-marketplace::marketplace.operations.marketplace') }}
                </x-filament::button>
            </div>
        </div>

        {{ $this->content }}

        <x-filament::section>
            @if ($selectedOperation)
                <x-slot name="heading">
                    {{ $selectedOperation->extension_name }}
                </x-slot>

                <x-slot name="description">
                    {{ $selectedOperation->composer_name }}
                </x-slot>

                <x-slot name="headerEnd">
                    <div class="flex flex-wrap gap-2">
                        @if ($this->canRetry($selectedOperation))
                            <x-filament::button
                                size="sm"
                                color="warning"
                                wire:click="retry({{ $selectedOperation->getKey() }})"
                            >
                                {{ __('capell-marketplace::marketplace.operations.retry') }}
                            </x-filament::button>
                        @endif

                        @if ($this->canCancel($selectedOperation))
                            <x-filament::button
                                size="sm"
                                color="danger"
                                wire:click="cancel({{ $selectedOperation->getKey() }})"
                                wire:confirm="{{ __('capell-marketplace::marketplace.operations.cancel_confirm') }}"
                            >
                                {{ __('capell-marketplace::marketplace.operations.cancel') }}
                            </x-filament::button>
                        @endif

                        @if ($this->canMarkResolved($selectedOperation))
                            <x-filament::button
                                size="sm"
                                color="gray"
                                wire:click="markResolved({{ $selectedOperation->getKey() }})"
                            >
                                {{ __('capell-marketplace::marketplace.operations.mark_resolved') }}
                            </x-filament::button>
                        @endif

                        <x-filament::button
                            size="sm"
                            color="gray"
                            wire:click="copyDiagnostics({{ $selectedOperation->getKey() }})"
                        >
                            {{ __('capell-marketplace::marketplace.operations.copy_diagnostics') }}
                        </x-filament::button>
                    </div>
                </x-slot>

                <dl class="grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <dt
                            class="font-medium text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-marketplace::marketplace.operations.command') }}
                        </dt>
                        <dd
                            class="mt-1 break-words text-gray-950 dark:text-white"
                        >
                            {{ $selectedOperation->composer_command ?: '-' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="font-medium text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-marketplace::marketplace.operations.requester') }}
                        </dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">
                            {{ $selectedOperation->user_email ?: '-' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="font-medium text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-marketplace::marketplace.operations.failure_classification') }}
                        </dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">
                            {{ $selectedOperation->failure_stage ?: '-' }}{{ $selectedOperation->failure_type ? ' / ' . $selectedOperation->failure_type : '' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="font-medium text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-marketplace::marketplace.operations.deployment_reference') }}
                        </dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">
                            {{ data_get($selectedOperation->deployment, 'reference', '-') }}
                        </dd>
                    </div>
                </dl>

                @if ($selectedOperation->failure_reason)
                    <x-filament::section
                        class="mt-6"
                        compact
                    >
                        <div
                            class="text-danger-700 dark:text-danger-300 text-sm"
                        >
                            {{ $selectedOperation->failure_reason }}
                        </div>
                    </x-filament::section>
                @endif

                <div class="mt-6">
                    <h3
                        class="text-sm font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-marketplace::marketplace.operations.timeline') }}
                    </h3>
                    <ol class="mt-3 space-y-3">
                        @forelse ($selectedOperation->events as $event)
                            <li
                                class="rounded-md border border-gray-200 p-3 text-sm dark:border-gray-800"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <span
                                        class="font-medium text-gray-950 dark:text-white"
                                    >
                                        {{ $event->message }}
                                    </span>
                                    <span
                                        class="text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        {{ $event->occurred_at?->toDateTimeString() }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ $event->level->value }}{{ $event->stage ? ' / ' . $event->stage->value : '' }}
                                </div>
                                @if ($event->output_excerpt)
                                    <pre
                                        class="mt-3 max-h-48 overflow-auto rounded-md bg-gray-950 p-3 text-xs text-gray-100"
                                    >
{{ $event->output_excerpt }}</pre>
                                @endif
                            </li>
                        @empty
                            <li
                                class="text-sm text-gray-500 dark:text-gray-400"
                            >
                                {{ __('capell-marketplace::marketplace.operations.timeline_empty') }}
                            </li>
                        @endforelse
                    </ol>
                </div>

                @if ($diagnosticBundle)
                    <div class="mt-6">
                        <label
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                            for="marketplace-diagnostic-bundle"
                        >
                            {{ __('capell-marketplace::marketplace.operations.diagnostic_bundle') }}
                        </label>
                        <textarea
                            id="marketplace-diagnostic-bundle"
                            readonly
                            class="mt-2 h-72 w-full rounded-md border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                        >
{{ $diagnosticBundle }}</textarea>
                    </div>
                @endif
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-marketplace::marketplace.operations.empty') }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
