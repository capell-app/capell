@props([
    'gap' => 'gap-y-2',
])

<ul
    {{ $attributes->class(['capell-component capell-list-index list list-items', 'flex flex-col', $gap]) }}
>
    {{ $slot }}
</ul>
