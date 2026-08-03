@props([
    'impact',
])

<div {{ $attributes->class('flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-300') }} role="status">
    @if ($impact->knownReferenceCount === 0)
        <span>
            {{ $impact->authoritative
                ? __('capell-admin::generic.deletion_impact_unused')
                : __('capell-admin::generic.deletion_impact_no_tracked_uses') }}
        </span>
    @elseif ($impact->referencesUrl !== null && $impact->referencesUrl !== '')
        <a
            href="{{ $impact->referencesUrl }}"
            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
        >
            {{ $impact->affectedLabel }}
        </a>
    @else
        <span>{{ $impact->affectedLabel }}</span>
    @endif

    @if ($impact->reviewLabel !== null && $impact->reviewLabel !== '')
        <span>{{ $impact->reviewLabel }}</span>
    @endif
</div>
