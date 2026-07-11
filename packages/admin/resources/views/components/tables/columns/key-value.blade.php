@php
    $record = $getRecord();
    $truncate_length = 120;
@endphp

<div
    {{ $attributes->merge($getExtraAttributes())->class(['filament-tables-key-value-column flex w-full flex-col gap-2 p-4']) }}
>
    <table class="w-full table-fixed text-sm">
        <thead class="border-b border-gray-100">
            <th class="w-1/2 p-2 text-left text-sm font-light">
                {{ __('capell-admin::table.old_value') }}
            </th>
            <th class="p-2 text-left text-sm font-light">
                {{ __('capell-admin::table.new_value') }}
            </th>
        </thead>
        <tbody>
            @foreach ($record->new_values as $key => $new_value)
                @php
                    $old_value = $record->old_values[$key] ?? null;
                @endphp

                @continue($old_value === $new_value)

                <tr>
                    <td class="border-r border-gray-100 px-2 pt-2">
                        <span
                            class="inline-block rounded bg-gray-50 px-2 py-1 text-xs font-semibold"
                        >
                            {{ $key }}
                        </span>
                    </td>
                    <td class="border-gray-100 px-2 pt-2">&nbsp;</td>
                </tr>
                <tr>
                    <td
                        @class([
                            'border-r border-gray-100 p-2 text-xs leading-tight text-gray-600',
                            'border-b' => ! $loop->last,
                        ])
                    >
                        <div
                            class="inline"
                            x-data="{
                                length: {{ $old_value && mb_strlen(strip_tags((string) $old_value)) > $truncate_length ? $truncate_length : 0 }},
                                originalContent: '',
                            }"
                            x-init="originalContent = $el.firstElementChild.textContent.trim()"
                            x-cloak
                        >
                            <span
                                class="break-words whitespace-normal"
                                x-text="length ? originalContent.slice(0, length) : originalContent"
                            >
                                {{ $old_value ? strip_tags($old_value) : '' }}
                            </span>
                            <button
                                class="text-primary-600 hover:text-primary-500 tracking-wide"
                                @click="length = undefined"
                                x-show="length"
                            >
                                {{ __('capell-admin::generic.more_truncate') }}
                            </button>
                        </div>
                    </td>
                    <td
                        @class([
                            'border-gray-100 p-2 text-xs leading-tight text-gray-600',
                            'border-b' => ! $loop->last,
                        ])
                    >
                        <div
                            class="inline"
                            x-data="{
                                length: {{ $new_value && mb_strlen(strip_tags((string) $new_value)) > $truncate_length ? $truncate_length : 0 }},
                                originalContent: '',
                            }"
                            x-init="originalContent = $el.firstElementChild.textContent.trim()"
                            x-cloak
                        >
                            <span
                                class="break-words whitespace-normal"
                                x-text="length ? originalContent.slice(0, length) : originalContent"
                            >
                                {{ $new_value ? strip_tags($new_value) : '' }}
                            </span>
                            <button
                                class="text-primary-600 hover:text-primary-500 font-bold tracking-wide"
                                @click="length = undefined"
                                x-show="length"
                            >
                                {{ __('capell-admin::generic.more_truncate') }}
                            </button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
