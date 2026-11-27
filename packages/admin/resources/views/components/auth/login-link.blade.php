@props(['url'])

<p class="text-center text-sm">
    <x-filament::link :href="$url">
        {{ __('capell-admin::login_links.login_page.link') }}
    </x-filament::link>
</p>
