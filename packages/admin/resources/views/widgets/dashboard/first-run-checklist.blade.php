@php
    $state = $this->state();
    $next = $state->nextTask();
@endphp
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.first_run_heading')"
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
        @if ($next !== null)
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                {{ __('capell-admin::dashboard.first_run_next', ['task' => $next->title]) }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
