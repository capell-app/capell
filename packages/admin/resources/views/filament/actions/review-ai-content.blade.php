@props(['fields' => []])

<ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
    @foreach ($fields as $field)
        <li class="flex items-center justify-between gap-4 py-2">
            <span class="font-medium text-gray-950 dark:text-white">
                {{ $field['field'] }}
                @if ($field['language'])
                    <span class="text-gray-500 dark:text-gray-400"
                        >· {{ $field['language'] }}</span
                    >
                @endif
            </span>
            <span
                class="text-gray-500 dark:text-gray-400"
                >{{ $field['disclosure'] }}</span
            >
        </li>
    @endforeach
</ul>
