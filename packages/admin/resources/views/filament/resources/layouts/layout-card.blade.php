@php
    use Capell\Admin\Support\Layouts\LayoutCardData;

    $layout = $getRecord();
    $card = LayoutCardData::fromLayout($layout);
@endphp

@once
    <style>
        .capell-layout-card-record {
            padding: 0;
            position: relative;
        }

        .capell-layout-card-record.fi-ta-record-with-content-prefix {
            grid-template-columns: minmax(0, 1fr);
        }

        .capell-layout-card-record > .fi-ta-record-checkbox {
            position: absolute;
            inset-inline-start: 1.5rem;
            top: 1.5rem;
            z-index: 20;
        }

        .capell-layout-card-record > .fi-ta-record-content-ctn {
            min-width: 0;
            padding: 0;
        }

        .fi-ta-content-ctn
            .fi-ta-content
            .capell-layout-card-record
            > .fi-ta-record-content-ctn
            .fi-ta-record-content {
            padding: 0;
        }

        .capell-layout-card-record .fi-ta-actions {
            align-items: center;
            background: rgb(255 255 255 / 0.92);
            border-radius: 0.5rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.08);
            gap: 0.25rem;
            inset-inline-end: 1rem;
            margin: 0;
            padding: 0.25rem;
            position: absolute;
            top: 1rem;
            z-index: 10;
        }

        .dark .capell-layout-card-record .fi-ta-actions {
            background: rgb(3 7 18 / 0.82);
            box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1);
        }

        .capell-layout-card-record .fi-ta-actions.fi-align-start {
            justify-content: flex-end;
        }
    </style>
@endonce

<div
    class="group overflow-hidden rounded-lg transition duration-150 hover:-translate-y-0.5"
>
    <div
        class="relative aspect-[16/10] w-full overflow-hidden bg-gray-100 dark:bg-gray-950"
    >
        @if ($card->imageUrl !== null)
            <img
                src="{{ $card->imageUrl }}"
                alt="{{ $card->title }}"
                class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                loading="lazy"
            />
        @else
            <div
                class="flex h-full w-full items-center justify-center bg-[radial-gradient(circle_at_top_left,rgba(var(--primary-500),0.18),transparent_34%),linear-gradient(135deg,rgba(17,24,39,0.04),rgba(17,24,39,0.12))] px-6 text-center dark:bg-[radial-gradient(circle_at_top_left,rgba(var(--primary-400),0.22),transparent_34%),linear-gradient(135deg,rgba(255,255,255,0.06),rgba(255,255,255,0.02))]"
            >
                <div class="max-w-52">
                    <div
                        class="text-[0.68rem] font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400"
                    >
                        {{ __('capell-admin::table.layout_preview_fallback') }}
                    </div>
                    <div
                        class="mt-2 truncate text-xl font-semibold text-gray-950 dark:text-white"
                    >
                        {{ $card->title }}
                    </div>
                </div>
            </div>
        @endif

        <div
            class="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/45 to-transparent"
        ></div>

        <div
            class="absolute right-3 bottom-3 left-3 flex items-end justify-between gap-3"
        >
            <div class="min-w-0">
                <h3 class="truncate text-base font-semibold text-white">
                    {{ $card->title }}
                </h3>
                <p class="mt-0.5 truncate text-xs text-white/75">
                    {{ $card->key }}
                </p>
            </div>

            @if ($card->isDefault)
                <span
                    class="bg-primary-500 shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-white shadow-sm"
                >
                    {{ __('capell-admin::table.default') }}
                </span>
            @endif
        </div>
    </div>

    <div class="space-y-4 p-4">
        <div
            class="min-h-8 space-y-1 text-sm leading-6 text-gray-600 dark:text-gray-300"
        >
            @if ($card->themeName !== null)
                <p class="truncate">{{ $card->themeName }}</p>
            @endif

            @if ($card->siteName !== null)
                <p class="truncate">{{ $card->siteName }}</p>
            @endif
        </div>

        <details
            class="group/details border-t border-gray-100 pt-3 dark:border-white/10"
        >
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-medium text-gray-700 marker:hidden dark:text-gray-200"
            >
                <span class="flex items-center gap-2">
                    <span
                        class="bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300 grid size-5 place-items-center rounded-md text-xs font-semibold"
                    >
                        {{ $card->containerCount }}
                    </span>
                    <span>
                        {{ __('capell-admin::table.layout_containers') }}
                    </span>
                </span>
                <span
                    class="text-xs font-medium text-gray-500 dark:text-gray-400"
                >
                    {{ __('capell-admin::table.expand') }}
                </span>
            </summary>

            <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                @forelse ($card->containerNames as $containerName)
                    <div
                        class="flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-gray-700 dark:bg-white/5 dark:text-gray-200"
                    >
                        <span
                            class="bg-primary-500 size-1.5 shrink-0 rounded-full"
                        ></span>
                        <span class="truncate">{{ $containerName }}</span>
                    </div>
                @empty
                    <p
                        class="text-sm text-gray-500 sm:col-span-2 dark:text-gray-400"
                    >
                        {{ __('capell-admin::table.layout_no_containers') }}
                    </p>
                @endforelse
            </div>
        </details>

        <div
            class="flex items-center justify-between border-t border-gray-100 pt-3 text-xs dark:border-white/10"
        >
            <span class="font-medium text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::table.last_updated') }}
                {{ $card->lastUpdated }}
            </span>

            <span
                @class([
                    'rounded-md px-2 py-1 font-medium',
                    'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-300' => $card->isEnabled,
                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $card->isEnabled,
                ])
            >
                {{ $card->isEnabled ? __('capell-admin::form.enabled') : __('capell-admin::form.disabled') }}
            </span>
        </div>
    </div>
</div>
