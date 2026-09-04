@php
    use Capell\Admin\Actions\Media\BuildMediaImpactPreviewAction;
    use Capell\Core\Models\Media;

    /** @var Media|null $record */
    $impact = $record instanceof Media ? BuildMediaImpactPreviewAction::run($record) : null;
@endphp

@if ($impact === null)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('capell-admin::media.impact_preview_unavailable') }}
    </p>
@elseif ($impact->groups === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('capell-admin::media.impact_preview_none') }}
    </p>
@else
    <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div
                class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('capell-admin::media.impact_preview_strong') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $impact->strongCount }}</p>
            </div>
            <div
                class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('capell-admin::media.impact_preview_weak') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $impact->weakCount }}</p>
            </div>
            <div
                class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('capell-admin::media.impact_preview_informational') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $impact->informationalCount }}</p>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('capell-admin::media.impact_preview_exact') }}
        </p>

        <div class="space-y-4">
            @foreach ($impact->groups as $group)
                <div
                    class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10"
                >
                    <div
                        class="flex items-baseline justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10"
                    >
                        <p class="font-medium text-gray-950 dark:text-white">{{ $group->label }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ trans_choice('capell-admin::media.impact_preview_dependency_count', $group->count, ['count' => $group->count]) }}
                        </p>
                    </div>

                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($group->dependencies as $dependency)
                            <div class="space-y-2 px-4 py-3">
                                <div
                                    class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                                >
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $dependency->name }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $dependency->type }} · {{ $dependency->site }}
                                    </p>
                                </div>

                                @if ($dependency->locales !== [])
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('capell-admin::media.impact_preview_locales', ['locales' => implode(', ', $dependency->locales)]) }}
                                    </p>
                                @endif

                                <div
                                    class="flex flex-wrap gap-x-4 gap-y-1 text-sm"
                                >
                                    @foreach ($dependency->urls as $url)
                                        <a
                                            href="{{ $url->url }}"
                                            target="_blank"
                                            rel="noreferrer"
                                            class="text-primary-600 hover:text-primary-500 dark:text-primary-400"
                                        >
                                            {{ $url->locale }}: {{ $url->url }}
                                        </a>
                                    @endforeach

                                    @if ($dependency->urls === [])
                                        <span
                                            class="text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-admin::media.impact_preview_no_public_urls') }}
                                        </span>
                                    @endif
                                </div>

                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    <span class="font-medium"
                                        >{{ __('capell-admin::media.impact_preview_consequence') }}:</span
                                    >
                                    {{ $dependency->consequence }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('capell-admin::media.impact_preview_cache') }}</p>
            <p>{{ __('capell-admin::media.impact_preview_graph') }}</p>
            <p>{{ __('capell-admin::media.impact_preview_reversibility') }}</p>
        </div>
    </div>
@endif
