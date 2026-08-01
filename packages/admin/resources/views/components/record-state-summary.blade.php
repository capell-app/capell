@props([
    'states' => [],
    'relationships' => [],
])

<div {{ $attributes->class('flex flex-wrap items-center gap-1.5') }}>
    @foreach ($states as $state)
        @include('capell-admin::components.record-state-chip', ['state' => $state])
    @endforeach

    @foreach ($relationships as $relationship)
        @if ($relationship->count > 0)
            @if ($relationship->url)
                <a
                    href="{{ $relationship->url }}"
                    class="inline-flex items-center gap-1 rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                >
                    {{ $relationship->label }}: {{ $relationship->count }}
                </a>
            @else
                <span
                    class="inline-flex items-center gap-1 rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300"
                >
                    {{ $relationship->label }}: {{ $relationship->count }}
                </span>
            @endif
        @endif
    @endforeach
</div>
