@php
    $state = $this->state();
    $next = $state->nextTask();
@endphp
<x-filament-widgets::widget>
    <x-filament::section
        :heading="$state->siteName === null ? __('capell-admin::dashboard.first_run_heading') : __('capell-admin::dashboard.first_run_heading_site', ['site' => $state->siteName])"
        :description="__('capell-admin::dashboard.first_run_description', ['done' => $state->completedCount(), 'total' => $state->totalCount()])"
    >
        <ol class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($state->tasks as $task)
                <li
                    class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                    data-first-run-task="{{ $task->key }}"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            <span class="sr-only">
                                {{ $task->complete ? __('capell-admin::dashboard.first_run_done') : __('capell-admin::dashboard.first_run_to_do') }}:
                            </span>
                            {{ $task->title }}
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $task->consequence }}</p>
                    </div>
                    @if (! $task->complete && $task->url !== null)
                        <x-filament::link
                            :href="$task->url"
                            class="shrink-0"
                            >{{ $task->actionLabel }}</x-filament::link
                        >
                    @endif
                </li>
            @endforeach
        </ol>
        <div
            class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            @if ($next !== null)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('capell-admin::dashboard.first_run_next', ['task' => $next->title]) }}
                </p>
            @endif
            <div class="flex items-center gap-3">
                <p
                    id="first-run-hide-help"
                    class="text-xs text-gray-500 dark:text-gray-400"
                >
                    {{ __('capell-admin::dashboard.first_run_hide_help') }}
                </p>
                <x-filament::button
                    color="gray"
                    size="sm"
                    wire:click="hide"
                    aria-describedby="first-run-hide-help"
                    data-first-run-hide
                >
                    {{ __('capell-admin::dashboard.first_run_hide') }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
