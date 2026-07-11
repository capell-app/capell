<?php
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\View\PublicModelMeta;

$theme = Frontend::theme();
$roundedImages = (bool) PublicModelMeta::get($theme, 'rounded_images', false);

?>

@props([
    'neighbor' => null,
    'neighborPage' => null,
    'withSummary' => true,
    'withImage' => true,
])
@php
    $pageUrl = $neighborPage !== null && method_exists($neighborPage, 'relationLoaded') && $neighborPage->relationLoaded('pageUrl') ? $neighborPage->pageUrl : null;
    $translation = $neighborPage !== null && method_exists($neighborPage, 'relationLoaded') && $neighborPage->relationLoaded('translation') ? $neighborPage->translation : null;
    $image = $neighborPage !== null && method_exists($neighborPage, 'relationLoaded') && $neighborPage->relationLoaded('image') ? $neighborPage->image : null;
    $title = $translation?->title ?? '';
    $label = $translation?->label ?? $title;
    $summary = $translation?->summary;
    $fullUrl = $pageUrl?->full_url;
    $showImage = $withImage && $image;
@endphp

@if ($fullUrl)
    <a
        href="{{ $fullUrl }}"
        title="{{ htmlspecialchars(strip_tags($title)) }}"
        {{
            $attributes->class([
                'capell-component capell-page-neighbor-link neighbor-link hover:text-primary focus:text-primary group flex max-w-[50%] items-center gap-x-4 gap-y-3 py-6 md:py-3 lg:gap-x-4',
                'neighbor-link-next ml-auto justify-end text-right md:pl-10' => $neighbor === 'next',
                'neighbor-link-prev md:pr-10' => $neighbor === 'previous',
            ])
        }}
        @wireNavigate
    >
        @if ($neighbor === 'previous')
            @svg('heroicon-s-chevron-left', 'group-hover:text-primary group-focus:text-primary relative h-8 w-8 shrink-0 opacity-25 group-hover:opacity-100 group-focus:opacity-100')
        @endif

        @if ($neighbor === 'previous' && $showImage)
            <x-capell::media
                :media="$image"
                :alt="$title"
                fit="crop"
                :width="200"
                @class([
                    'h-full w-auto max-w-[5rem] object-cover object-center group-hover:opacity-85 group-focus:opacity-85',
                    'rounded' => $roundedImages,
                ])
                loading="lazy"
            />
        @endif

        <span class="flex flex-col">
            <span class="mb-1 text-xs font-bold text-gray-600 uppercase">
                {{ $neighbor === 'previous' ? __('Previous') : __('Next') }}
            </span>
            <span
                class="line-clamp-1 text-base font-medium text-current group-hover:underline group-focus:underline"
            >
                {{ strip_tags($label) }}
            </span>
            @if ($withSummary && $summary)
                <span
                    class="mt-0.5 line-clamp-2 text-sm break-words text-gray-500"
                >
                    {{ strip_tags($summary) }}
                </span>
            @endif
        </span>

        @if ($neighbor === 'next' && $showImage)
            <x-capell::media
                :media="$image"
                :alt="$title"
                fit="crop"
                :width="200"
                @class([
                    'h-full w-auto max-w-[5rem] object-cover object-center group-hover:opacity-85 group-focus:opacity-85',
                    'rounded' => $roundedImages,
                ])
                loading="lazy"
            />
        @endif

        @if ($neighbor === 'next')
            @svg('heroicon-s-chevron-right', 'group-hover:text-primary group-focus:text-primary relative h-8 w-8 shrink-0 opacity-25 group-hover:opacity-100 group-focus:opacity-100')
        @endif
    </a>
@endif
