@if ($entry !== null)
    <section
        class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
        data-publishing-studio-workflow-entry
        aria-labelledby="publishing-workflow-entry-heading"
    >
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h2
                        id="publishing-workflow-entry-heading"
                        class="text-sm font-semibold text-gray-950 dark:text-white"
                    >
                        {{ $entry->label }}
                    </h2>

                    <span
                        class="bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30 rounded-md px-2 py-1 text-xs font-medium ring-1"
                    >
                        {{ trans_choice('capell-admin::dashboard.publishing_workflow_count', $entry->count, ['count' => $entry->count]) }}
                    </span>
                </div>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ $entry->description }}
                </p>
            </div>

            <a
                href="{{ $entry->url }}"
                class="bg-primary-600 hover:bg-primary-500 focus-visible:outline-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400 inline-flex shrink-0 items-center justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                {{ $entry->actionLabel }}
            </a>
        </div>
    </section>
@endif
