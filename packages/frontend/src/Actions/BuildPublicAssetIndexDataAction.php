<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Data\ImageSourceData;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicAssetIndexData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\View\PublicModelMeta;
use Capell\Frontend\Support\View\PublicPageRelations;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicAssetIndexDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend, private readonly RenderHookRegistry $hooks) {}

    /** @param array<string, mixed>|ImageSourceData|MediaContract|string|null $image */
    public function handle(
        ?object $author,
        int|string|null $count,
        ?bool $hasBullet,
        ?string $headingSize,
        ?string $icon,
        ImageSourceData|MediaContract|array|string|null $image,
        ?string $linkText,
        ?object $parent,
        ?CarbonInterface $publishDate,
        string $publishDatePosition,
        ?string $size,
        bool $squareImage,
        ?string $summary,
        ?string $title,
        bool $titleBalance,
        ?string $url,
        bool $withSummary,
        string $class,
        mixed $item,
    ): PublicAssetIndexData {
        $theme = $this->frontend->theme();
        $title ??= '';
        $hasImage = (bool) $image;
        $hasContent = ($withSummary && $summary) || $parent || $publishDate instanceof CarbonInterface || $title || ($linkText && $url);
        $isBlogArticleCard = str_contains($class, 'capell-blog-article-card');
        $imageClass = 'h-full w-full object-cover object-center' .
            ($url ? ' transition duration-300 group-hover:scale-[1.03]' : '') .
            ((bool) PublicModelMeta::get($theme, 'rounded_images', false) ? ' rounded' : '') .
            ($squareImage ? ' aspect-square' : '');
        $renderedImage = $hasImage ? Blade::render(<<<'BLADE'
            <x-capell::image-source
                :image="$image"
                loading="lazy"
                :alt="$title"
                :width="720"
                :height="405"
                sizes="(min-width: 1024px) 30vw, (min-width: 768px) 45vw, 92vw"
                :class="$imageClass"
            />
            BLADE, ['image' => $image, 'title' => $title, 'imageClass' => $imageClass]) : '';
        $translation = PublicPageRelations::loaded($parent, 'translation');
        $parentLabel = $translation->label ?? null;

        return new PublicAssetIndexData(
            title: $title,
            headingSize: $headingSize ?: null,
            summary: $summary ?? '',
            url: $url ?? '',
            linkText: $linkText ?? '',
            icon: $icon ?? '',
            size: $size,
            count: $count,
            titleBalance: $titleBalance,
            withSummary: $withSummary,
            hasBullet: $hasBullet ?? (bool) PublicModelMeta::get($theme, 'list_bullets', false),
            hasContent: $hasContent,
            hasImage: $hasImage,
            hasDate: $publishDate instanceof CarbonInterface,
            isBlogArticleCard: $isBlogArticleCard,
            cardStyle: $isBlogArticleCard && $hasImage
                ? 'max-width: min(100%, calc(100vw - 3.75rem)); grid-template-columns: minmax(9.5rem, 10.5rem) minmax(0, 1fr);'
                : 'max-width: min(100%, calc(100vw - 3.75rem));',
            renderedImage: $renderedImage,
            renderedDate: $hasContent && $publishDate instanceof CarbonInterface && in_array($publishDatePosition, ['top', 'bottom'], true) ? $this->renderDate($publishDate, $author) : '',
            datePosition: $publishDatePosition,
            parentUrl: $parentLabel ? PublicPageRelations::fullUrl($parent) : null,
            parentLabel: $parentLabel,
            beforeTitle: $hasContent ? $this->hooks->renderAll(RenderHookLocation::BeforeTitle, $item) : '',
            afterTitle: $hasContent ? $this->hooks->renderAll(RenderHookLocation::AfterTitle, $item) : '',
            footer: $this->hooks->renderAll(RenderHookLocation::Footer, $item),
        );
    }

    private function renderDate(CarbonInterface $publishDate, ?object $author): string
    {
        $formattedDate = $publishDate->format(config('capell-frontend.date_format'));
        $calendarIcon = svg('heroicon-o-calendar', 'mr-1.5 inline-block h-4 w-4 align-middle text-gray-500')->toHtml();
        $authorOutput = $author
            ? '<span class="ml-1 break-words">' . e(__('capell-frontend::generic.editor_by', ['name' => $author->name ?? ''])) . '</span>'
            : '';

        return '<time class="publish-date whitespace-nowrap inline-flex items-center text-xs font-semibold uppercase leading-tight tracking-[0.06em] text-gray-500" title="' .
            e(__('capell-frontend::generic.publish_date', ['date' => $formattedDate])) .
            '" datetime="' . e($publishDate->toW3cString()) . '">' .
            '<span class="inline-flex items-center whitespace-nowrap">' .
            $calendarIcon .
            '<span>' . e($formattedDate) . '</span>' .
            '</span>' .
            $authorOutput .
            '</time>';
    }
}
