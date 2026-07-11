<x-filament-widgets::widget>
    <x-filament::section>
        <div class="space-y-4">
            <div
                class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
            >
                <div>
                    <p
                        class="text-danger-700 dark:text-danger-300 text-sm font-semibold tracking-wide uppercase"
                    >
                        {{ __('capell-admin::dashboard.update_advisory_eyebrow') }}
                    </p>
                    <h2
                        class="mt-1 text-base font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::dashboard.update_advisory_title') }}
                    </h2>
                    <p
                        class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-300"
                    >
                        {{ __('capell-admin::dashboard.update_advisory_description') }}
                    </p>
                </div>

                <a
                    href="{{ $this->upgradeUrl() }}"
                    class="bg-danger-600 hover:bg-danger-500 dark:bg-danger-500 dark:hover:bg-danger-400 inline-flex shrink-0 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-white transition"
                >
                    @svg('heroicon-m-arrow-up-circle', 'h-4 w-4')
                    {{ __('capell-admin::dashboard.update_advisory_open_upgrade_center') }}
                </a>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($this->advisories() as $advisory)
                    <article
                        class="border-danger-200 bg-danger-50 dark:border-danger-800/60 dark:bg-danger-950/30 rounded-xl border p-4"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="bg-danger-100 text-danger-700 dark:bg-danger-900/60 dark:text-danger-200 rounded-full px-2 py-1 text-xs font-semibold tracking-wide uppercase"
                            >
                                {{ data_get($advisory, 'severity', __('capell-admin::generic.unknown')) }}
                            </span>
                            <span
                                class="text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ data_get($advisory, 'notice_id', data_get($advisory, 'id', '')) }}
                            </span>
                        </div>

                        <h3
                            class="mt-2 text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ data_get($advisory, 'title', __('capell-admin::generic.security_advisory')) }}
                        </h3>

                        <p
                            class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-300"
                        >
                            {{ data_get($advisory, 'summary', __('capell-admin::generic.security_advisory_summary_missing')) }}
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
