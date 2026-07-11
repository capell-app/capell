@props(['media'])

<x-capell::media
    :media="$media"
    :alt="$media->name"
    class="capell-component capell-logo-index h-10 w-auto"
/>
