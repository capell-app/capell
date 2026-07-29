@php
    $state = $getState();
    $payload = is_array($state) && array_key_exists('payload', $state) ? $state['payload'] : null;
@endphp

@if ($payload === null || (is_array($payload) && $payload === []))
    <p class="text-sm text-gray-500 italic dark:text-gray-400">
        {{ __('capell-admin::exchanger.json_empty_placeholder') }}
    </p>
@else
    <pre
        class="overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900"
    ><code>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
@endif
