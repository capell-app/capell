@if ($navigation !== [])
    <nav aria-label="{{ __('capell-admin::navigation.section_navigation') }}">
        <x-filament-panels::page.sub-navigation.mobile-menu
            :navigation="$navigation"
        />
        <x-filament-panels::page.sub-navigation.tabs
            :navigation="$navigation"
        />
    </nav>
@endif
