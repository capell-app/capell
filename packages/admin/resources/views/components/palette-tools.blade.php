@php
    $access = resolve(\Capell\Admin\Support\PaletteAccess::class)->forRender();
@endphp

<x-capell-admin::keyboard-shortcuts :shortcuts="$access->shortcuts" />
@livewire('capell-admin::global-command-palette', ['access' => $access])
