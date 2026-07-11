<x-filament-panels::page>
    @php
        $snapshot = $this->reportSnapshot();
    @endphp

    @if ($snapshot->metrics !== [] || $snapshot->findings !== [])
        @if ($snapshot->metrics !== [])
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($snapshot->metrics as $metric)
                    <div
                        class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
                    >
                        <div
                            class="text-sm font-medium text-gray-500 dark:text-gray-400"
                        >
                            {{ $metric->label }}
                        </div>
                        <div
                            class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $metric->value }}
                        </div>

                        @if ($metric->description !== null)
                            <div
                                class="mt-2 text-sm text-gray-600 dark:text-gray-300"
                            >
                                {{ $metric->description }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($snapshot->findings !== [])
            <div class="mt-6 space-y-3">
                <h2
                    class="text-base font-semibold text-gray-950 dark:text-white"
                >
                    {{ __('capell-admin::reports.findings_heading') }}
                </h2>

                <div class="space-y-3">
                    @foreach ($snapshot->findings as $finding)
                        @php
                            $severityClasses = match ($finding->severity->value) {
                                'critical' => 'bg-danger-500/10 text-danger-700 dark:text-danger-300',
                                'warning' => 'bg-warning-500/10 text-warning-700 dark:text-warning-300',
                                'info' => 'bg-info-500/10 text-info-700 dark:text-info-300',
                            };
                        @endphp

                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="{{ $severityClasses }} rounded-md px-1.5 py-0.5 text-xs font-semibold"
                                        >
                                            {{ __('capell-admin::reports.finding_severity.' . $finding->severity->value) }}
                                        </span>

                                        @if ($finding->recordLabel !== null)
                                            <span
                                                class="text-sm font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ $finding->recordLabel }}
                                            </span>
                                        @endif
                                    </div>

                                    <div
                                        class="mt-2 text-sm font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ $finding->title }}
                                    </div>

                                    <p
                                        class="mt-1 text-sm text-gray-600 dark:text-gray-300"
                                    >
                                        {{ $finding->description }}
                                    </p>
                                </div>

                                @if ($finding->url !== null && $finding->actionLabel !== null)
                                    <a
                                        href="{{ $finding->url }}"
                                        class="text-primary-600 hover:text-primary-500 dark:text-primary-400 shrink-0 text-sm font-medium"
                                    >
                                        {{ $finding->actionLabel }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div
            class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
        >
            {{ $snapshot->emptyState }}
        </div>
    @endif
</x-filament-panels::page>
