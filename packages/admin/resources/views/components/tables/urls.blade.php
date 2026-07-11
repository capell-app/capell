@props([
    'urls' => null,
    'separator' => ', ',
])

@php
    use Illuminate\Support\Collection;

    if ($urls instanceof Collection) {
        $urls = $urls->all();
    }

    // Ensure we always have an array to avoid warnings from array_is_list()
    if ($urls === null) {
        $urls = [];
    }

    $items = [];

    // Support multiple input shapes:
    // - associative array: label => url
    // - list of strings: [ 'Label 1', 'Label 2' ] (label and url are the same)
    // - list of arrays: [ ['label' => 'Foo', 'url' => '/foo'], ... ]
    if (! array_is_list($urls)) {
        foreach ($urls as $label => $url) {
            $items[] = ['label' => (string) $label, 'url' => $url];
        }
    } else {
        foreach ($urls as $entry) {
            if (is_array($entry)) {
                $entryLabel = isset($entry['label']) ? (string) $entry['label'] : '';
                $entryUrl = $entry['url'] ?? ($entry['label'] ?? '');

                $items[] = ['label' => $entryLabel, 'url' => $entryUrl];
            } else {
                // treat scalar entries as both label and url
                $items[] = ['label' => (string) $entry, 'url' => $entry];
            }
        }
    }

    $pieces = [];
    foreach ($items as $item) {
        $label = $item['label'] ?? '';
        $url = $item['url'] ?? '';

        if ($label === '') {
            continue;
        }

        $pieces[] = '<a class="text-primary-500 hover:text-primary-500 focus:text-primary-500 transition focus:underline" href="' . e($url) . '">' . e($label) . '</a>';
    }
@endphp

@if (! empty($pieces))
    {!! implode($separator, $pieces) !!}
@endif
