{{-- format-ignore-start --}}
@props([
    'asset',
    'componentItem',
    'size' => null,
    'withAuthor' => false,
    'withChildCount' => false,
    'withLinkText' => false,
    'withImage' => false,
    'withParent' => false,
    'withDate' => false,
    'withSummary' => false,
    'withUrl' => true,
    'headingSize' => null,
    'imagePosition' => 'left',
    'loop',
])
@php
	use Capell\Core\Actions\ResolveRenderableComponentAction;
	use Capell\Core\Enums\RenderableTypeEnum;
	use Capell\Frontend\Support\View\PublicModelMeta;

$language = \Capell\Frontend\Facades\Frontend::language();
$page = \Capell\Frontend\Facades\Frontend::page();

$ancestors = null;
$parent = null;
$translation = method_exists($asset, 'relationLoaded') && $asset->relationLoaded('translation') ? $asset->translation : null;
	$linkText = PublicModelMeta::get($translation, 'link_text', __('capell-frontend::generic.read_more'));
$pageUrl = method_exists($asset, 'relationLoaded') && $asset->relationLoaded('pageUrl') ? $asset->pageUrl : null;

if ($withParent && method_exists($asset, 'relationLoaded')) {
    $parent = $asset->relationLoaded('parent') ? $asset->parent : null;
    $ancestors = $asset->relationLoaded('ancestors') ? $asset->ancestors : null;

    if ($ancestors instanceof \Illuminate\Database\Eloquent\Collection && $ancestors->count() === 1 && $ancestors->first()->id === $page->id) {
        $ancestors = false;
    }
}

$image = null;
if ($withImage && method_exists($asset, 'relationLoaded')) {
    $image = $asset->relationLoaded('image') ? $asset->image : ($asset->relationLoaded('media') ? $asset->getRelation('media')->first() : null);
    $image ??= PublicModelMeta::get($asset, 'image_source');
}

$componentItem = ResolveRenderableComponentAction::run(RenderableTypeEnum::Asset, $componentItem);
@endphp
{{-- format-ignore-end --}}
<x-dynamic-component
    :component="$componentItem"
    :$asset
    :$loop
    :$size
    :ancestor="$withParent ? $ancestors : null"
    :author="$withAuthor && method_exists($asset, 'relationLoaded') && $asset->relationLoaded('creator') ? $asset->creator : null"
    :count="$withChildCount ? $asset->children_count : null"
    :image="$image"
    :heading-size="$headingSize"
    :image-position="$imagePosition"
    :link-text="$withLinkText ? $linkText : null"
    :parent="$withParent ? $parent : null"
    :publish-date="$withDate ? $asset->getPublishDate() : null"
    :summary="$withSummary ? $translation?->summary : null"
    :title="$translation?->label"
    :url="$withUrl ? $pageUrl?->full_url : null"
    :attributes="$attributes->merge(['class' => 'capell-component capell-page-asset page-asset'])"
/>
