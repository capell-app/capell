<?php
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Site;
use Capell\Frontend\Facades\Frontend;

$theme = Frontend::theme();
$page = Frontend::page();
$layout = Frontend::layout();
$site = Frontend::site();
$isSystemPageLayout = data_get($layout->admin ?? [], 'system_page_layout') === true;
$siteHomeUrl = $site instanceof Site && $site->relationLoaded('defaultDomain')
    ? ($site->defaultDomain?->url ?? '/')
    : ($site instanceof Site && $site->relationLoaded('siteDomain') ? ($site->siteDomain?->url ?? '/') : '/');
$siteLogoBladeView = $site instanceof Site ? $site->getMeta('logo_blade_view', 'brand.capell-logo') : 'brand.capell-logo';
$siteLogoBladeView = is_string($siteLogoBladeView) && view()->exists($siteLogoBladeView)
    ? $siteLogoBladeView
    : null;
$siteLogo = $site instanceof Site && $site->relationLoaded('logo') ? $site->logo : null;
$siteTranslation = $site instanceof Site && $site->relationLoaded('translation') ? $site->translation : null;
$pageTranslation = $page instanceof Pageable && $page->relationLoaded('translation') ? $page->translation : null;
$pageType = $page instanceof Pageable && $page->relationLoaded('blueprint') ? $page->blueprint : null;
$htmlContentStructure = ContentStructure::Html;

?>

@props([
    'containerClass' => null,
    'footer' => null,
    'header' => null,
    'mainClass' => null,
    'mainContainerClass' => null,
    'pageSlot' => null,
])
@if ($isSystemPageLayout)
    <div
        {{ $attributes->merge(['class' => 'capell-component capell-layout-index flex min-h-screen flex-col bg-slate-50 text-slate-950']) }}
    >
        <main
            id="main"
            class="capell-component capell-layout-main mx-auto flex min-h-screen w-full max-w-3xl flex-col items-center justify-center px-6 py-12 text-center"
        >
            <a
                href="{{ $siteHomeUrl }}"
                class="mb-10 inline-flex items-center justify-center text-lg font-medium text-slate-950"
            >
                @if ($siteLogoBladeView)
                    @include($siteLogoBladeView, ['class' => 'h-10 w-auto'])
                @elseif ($siteLogo)
                    <x-capell::logo :media="$siteLogo" />
                @else
                    <span>{{ $siteTranslation?->title ?? $site->name }}</span>
                @endif
            </a>

            <x-capell::content
                :content="$pageTranslation?->content ?? ''"
                :content-type="$pageType?->content_structure ?? $htmlContentStructure"
                :title="$pageTranslation?->title ?? ''"
                class="mx-auto max-w-2xl text-slate-700 [&_h1]:text-slate-950"
                heading-tag="h1"
                heading-size="h1"
                text-align="center"
            />

            {{ $pageSlot ?? $slot }}
        </main>
    </div>
@else
    <div
        {{ $attributes->merge(['class' => 'capell-component capell-layout-index flex min-h-screen flex-col']) }}
    >
        <a
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-gray-900 focus:shadow"
            href="#main"
        >
            {{ __('capell-frontend::generic.skip_link') }}
        </a>

        @if ($header)
            {{ $header }}
        @elseif ($header === null && (! isset($theme['meta']['header']) || $theme['meta']['header'] !== false) && ! empty($theme['meta']['header_file']))
            @if (view()->exists($theme['meta']['header_file']))
                {!! view($theme['meta']['header_file'])->render() !!}
            @else
                <x-dynamic-component
                    :component="$theme['meta']['header_file']"
                />
            @endif
        @endif

        <x-capell::layout.main
            :$layout
            :$page
            :$theme
            :page-slot="$pageSlot ?? $slot"
            :container-class="$containerClass"
            :main-class="$mainClass"
            :main-container-class="$mainContainerClass"
        />

        @if ($footer)
            {{ $footer }}
        @elseif ($footer === null && (! isset($theme['meta']['footer']) || $theme['meta']['footer'] !== false) && ! empty($theme['meta']['footer_file']))
            @if (view()->exists($theme['meta']['footer_file']))
                {!! view($theme['meta']['footer_file'])->render() !!}
            @else
                <x-dynamic-component
                    :component="$theme['meta']['footer_file']"
                />
            @endif
        @endif
    </div>
@endif
