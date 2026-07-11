@props([
    'state',
    'url',
])

@if ($state)
    <a
        class="text-primary-500 hover:text-primary-500 focus:text-primary-500 transition focus:underline"
        href="{{ $url }}"
    >
        {{ $state }}
    </a>
@endif
