<div class="space-y-5">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('capell-admin::generic.frontend_resource_diagnostics_description') }}
    </p>

    <dl
        class="grid grid-cols-2 gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10"
    >
        @foreach ($context as $label => $value)
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">
                    {{ ucfirst($label) }}
                </dt>
                <dd class="text-gray-950 dark:text-white">
                    {{ $value ?: '-' }}
                </dd>
            </div>
        @endforeach
    </dl>

    <div class="grid gap-3 md:grid-cols-3">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs font-medium text-gray-500 uppercase">
                {{ __('capell-admin::generic.css') }}
            </div>
            <div class="mt-1 font-mono text-sm text-gray-950 dark:text-white">
                {{ number_format($report->byteCounts['cssRaw'] ?? 0) }} /
                {{ number_format($report->byteCounts['cssGzip'] ?? 0) }}
                {{ __('capell-admin::generic.bytes') }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs font-medium text-gray-500 uppercase">
                {{ __('capell-admin::generic.javascript') }}
            </div>
            <div class="mt-1 font-mono text-sm text-gray-950 dark:text-white">
                {{ number_format($report->byteCounts['jsRaw'] ?? 0) }} /
                {{ number_format($report->byteCounts['jsGzip'] ?? 0) }}
                {{ __('capell-admin::generic.bytes') }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs font-medium text-gray-500 uppercase">
                {{ __('capell-admin::generic.budget') }}
            </div>
            <div class="mt-1 text-sm font-medium">
                @if ($budgetResult->passes)
                    <span class="text-success-600 dark:text-success-400">
                        {{ __('capell-admin::generic.passing') }}
                    </span>
                @else
                    <span class="text-warning-600 dark:text-warning-400">
                        {{ __('capell-admin::generic.needs_attention') }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if (! $budgetResult->passes)
        <div
            class="border-warning-200 bg-warning-50 text-warning-900 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-100 rounded-lg border p-3 text-sm"
        >
            <div class="font-medium">
                {{ __('capell-admin::generic.budget_warnings') }}
            </div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($budgetResult->failures as $failure)
                    <li>{{ $failure }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-lg border border-gray-200 dark:border-white/10">
        <div
            class="border-b border-gray-200 px-3 py-2 text-sm font-medium text-gray-950 dark:border-white/10 dark:text-white"
        >
            {{ __('capell-admin::generic.resource_conflicts') }}
        </div>
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($conflicts as $conflict)
                <div class="px-3 py-3 text-sm">
                    <div
                        class="font-mono text-xs text-gray-600 dark:text-gray-400"
                    >
                        {{ $conflict['source'] }}
                    </div>
                    <div class="mt-1 text-gray-700 dark:text-gray-300">
                        {{ count($conflict['variants']) }}
                        {{ __('capell-admin::generic.conflicting_variants') }}
                    </div>
                </div>
            @empty
                <div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.no_resource_conflicts') }}
                </div>
            @endforelse
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 dark:border-white/10">
        <div
            class="border-b border-gray-200 px-3 py-2 text-sm font-medium text-gray-950 dark:border-white/10 dark:text-white"
        >
            {{ __('capell-admin::generic.resource_graph') }}
        </div>
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($graph['assets'] ?? [] as $asset)
                <div class="px-3 py-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div
                                class="font-mono text-xs text-gray-950 dark:text-white"
                            >
                                {{ $asset['source'] }}
                            </div>
                            <div
                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ $asset['kind'] }} ·
                                {{ $asset['loadingStrategy'] }}
                            </div>
                        </div>
                        <span
                            class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700 dark:bg-white/10 dark:text-gray-300"
                        >
                            {{ $asset['buildPath'] ?: 'public' }}
                        </span>
                    </div>
                    <ul
                        class="mt-2 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300"
                    >
                        @forelse ($asset['reasons'] as $reason)
                            <li>{{ $reason }}</li>
                        @empty
                            <li>
                                {{ __('capell-admin::generic.resource_reason_unavailable') }}
                            </li>
                        @endforelse
                    </ul>
                </div>
            @empty
                <div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.resource_graph_empty') }}
                </div>
            @endforelse
        </div>
    </div>
</div>
