@props([
    'asset',
    'ancestor' => null,
    'author' => null,
    'count' => null,
    'headingSize' => null,
    'image' => null,
    'imagePosition' => null,
    'linkText' => null,
    'loop' => null,
    'parent' => null,
    'publishDate' => null,
    'publishDatePosition' => null,
    'size' => null,
    'squareImage' => false,
    'summary' => null,
    'title' => null,
    'url' => null,
    'withAuthor' => false,
    'withSummary' => false,
])
<article {{ $attributes }}>
    <h3>{{ $title }}</h3>
    <p>{{ $summary }}</p>
    <a href="{{ $url }}">{{ $linkText }}</a>
    <span
        >{{ $asset->translation?->title }} {{ $loop?->iteration }} {{ $loop?->count }}</span
    >
    <span
        >{{ $author?->name }} {{ $count }} {{ $parent?->translation?->title }}</span
    >
    <span
        >{{ $publishDate?->format('Y-m-d') }} {{ $publishDatePosition }} {{ $size }} {{ $headingSize }} {{ $imagePosition }} {{ $squareImage ? 'square' : 'wide' }}</span
    >
    <span
        >{{ $image?->name }} {{ $ancestor === false ? 'current-parent' : ($ancestor?->count() ?? '') }}</span
    >
</article>
