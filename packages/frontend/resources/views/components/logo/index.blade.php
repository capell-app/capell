@props(['media'])

<x-capell::media
    :media="$media"
    :alt="$media->name"
    loading="eager"
    class="capell-component capell-logo-index h-10 w-auto"
/>
