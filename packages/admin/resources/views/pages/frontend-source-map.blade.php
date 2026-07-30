<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('capell-admin::generic.frontend_source_map_description') }}
    </p>

    <div
        class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10"
    >
        <table
            class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10"
        >
            <thead
                class="bg-gray-50 text-left text-xs font-medium tracking-wide text-gray-500 uppercase dark:bg-white/5 dark:text-gray-400"
            >
                <tr>
                    <th class="px-3 py-2">
                        {{ __('capell-admin::table.type') }}
                    </th>
                    <th class="px-3 py-2">
                        {{ __('capell-admin::table.preview') }}
                    </th>
                    <th class="px-3 py-2">
                        {{ __('capell-admin::table.model') }}
                    </th>
                    <th class="px-3 py-2">
                        {{ __('capell-admin::table.field') }}
                    </th>
                    <th class="px-3 py-2 text-right">
                        {{ __('capell-admin::generic.edit') }}
                    </th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900"
            >
                @forelse ($items as $item)
                    <tr>
                        <td
                            class="px-3 py-3 font-medium text-gray-950 dark:text-white"
                        >
                            {{ $item->typeLabel }}
                        </td>
                        <td
                            class="max-w-xs px-3 py-3 text-gray-700 dark:text-gray-300"
                        >
                            {{ $item->preview }}
                        </td>
                        <td
                            class="px-3 py-3 font-mono text-xs text-gray-600 dark:text-gray-400"
                        >
                            {{ $item->modelReference }}
                        </td>
                        <td
                            class="px-3 py-3 font-mono text-xs text-gray-600 dark:text-gray-400"
                        >
                            {{ $item->fieldPath }}
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if ($item->editUrl)
                                <a
                                    href="{{ $item->editUrl }}"
                                    class="text-primary-600 hover:text-primary-500 dark:text-primary-400 font-medium"
                                >
                                    {{ __('capell-admin::generic.edit') }}
                                </a>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-admin::generic.frontend_source_map_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
