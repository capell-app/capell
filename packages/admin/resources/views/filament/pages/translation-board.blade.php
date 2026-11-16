<x-filament-panels::page>
    @if (count($sites) > 1)
        <div class="max-w-sm">
            <label
                for="translation-board-site"
                class="sr-only"
            >
                {{ __('capell-admin::translation_board.site') }}
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select
                    id="translation-board-site"
                    wire:model.live="site_id"
                >
                    @foreach ($sites as $siteId => $siteName)
                        <option value="{{ $siteId }}">{{ $siteName }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif

    @if (count($languages) < 2)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('capell-admin::translation_board.single_language_heading') }}
            </x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('capell-admin::translation_board.single_language_body') }}
            </p>
        </x-filament::section>
    @elseif ($rows === [])
        <x-filament::section>
            <x-slot name="heading">
                {{ __('capell-admin::translation_board.no_pages_heading') }}
            </x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('capell-admin::translation_board.no_pages_body') }}
            </p>
        </x-filament::section>
    @else
        <div
            class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900"
        >
            <table class="w-full text-start text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th
                            scope="col"
                            class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-admin::translation_board.page') }}
                        </th>
                        @foreach ($languages as $language)
                            <th
                                scope="col"
                                class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white"
                            >
                                {{ $language->name }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($rows as $row)
                        <tr
                            wire:key="translation-board-page-{{ $row->pageId }}"
                        >
                            <th
                                scope="row"
                                class="px-4 py-3 text-start font-medium text-gray-950 dark:text-white"
                            >
                                @if ($row->editUrl)
                                    <a
                                        href="{{ $row->editUrl }}"
                                        class="hover:underline"
                                        aria-label="{{ __('capell-admin::translation_board.open_page', ['title' => $row->title]) }}"
                                    >
                                        {{ $row->title }}
                                    </a>
                                @else
                                    {{ $row->title }}
                                @endif
                            </th>
                            @foreach ($row->cells as $cell)
                                <td class="px-4 py-3">
                                    <x-filament::badge
                                        :color="$cell->status->color()"
                                        :tooltip="$cell->status->description()"
                                        data-status="{{ $cell->status->value }}"
                                    >
                                        {{ $cell->status->label() }}
                                    </x-filament::badge>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($pages)
            <x-filament::pagination :paginator="$pages" />
        @endif

        <x-filament::section
            :heading="__('capell-admin::translation_board.legend')"
            collapsible
            collapsed
        >
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                @foreach ($statuses as $status)
                    <div>
                        <dt>
                            <x-filament::badge
                                :color="$status->color()"
                                >{{ $status->label() }}</x-filament::badge
                            >
                        </dt>
                        <dd class="mt-1 text-gray-600 dark:text-gray-300">
                            {{ $status->description() }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif
</x-filament-panels::page>
