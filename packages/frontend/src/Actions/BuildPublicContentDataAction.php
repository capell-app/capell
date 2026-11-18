<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicContentData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Stringable;
use Throwable;

/** Resolves the frontend context a public content block needs and renders its body, so the view receives data only. */
final class BuildPublicContentDataAction
{
    use AsFake;
    use AsObject;

    public const int IMAGE_WIDTH = 360;

    public function __construct(private readonly FrontendContextReader $frontend) {}

    /** @param  array<array-key, mixed>|string|null  $content */
    public function handle(
        array|string|null $content,
        mixed $contentType,
        mixed $title,
        ?object $image = null,
        ?Pageable $pageRecord = null,
        ?Site $site = null,
        ?object $layout = null,
        ?object $theme = null,
        mixed $urlParams = null,
    ): PublicContentData {
        $page = $pageRecord ?? $this->resolve(fn (): ?Pageable => $this->frontend->page());
        $site ??= $this->resolve(fn (): ?Site => $this->frontend->site());
        $layout ??= $this->resolve(fn (): ?Layout => $this->frontend->layout());
        $theme ??= $this->resolve(fn (): ?Theme => $this->frontend->theme());

        $pageVariables = GetPageVariablesAction::run($page, $site, is_array($urlParams) ? $urlParams : []);
        $translationVariables = collect($pageVariables)
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof Stringable)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        if (is_string($content)) {
            $content = __($content, $translationVariables);
        }

        $bodyHtml = $contentType === ContentStructure::Blocks
            ? RenderContentAction::run($content, ContentStructure::Blocks, ['layout' => $layout, 'page' => $page])
            : e(RenderHtmlContentAction::run($content, $pageVariables));

        return new PublicContentData(
            title: $this->translatedTitle($title, $translationVariables),
            bodyHtml: is_string($bodyHtml) ? $bodyHtml : '',
            roundedImages: (bool) PublicModelMeta::get($theme, 'rounded_images', false),
            imageHeight: $this->imageHeight($image),
        );
    }

    /**
     * A null title stays null so an image falls back to its own alt text.
     *
     * @param  array<string, string>  $variables
     */
    private function translatedTitle(mixed $title, array $variables): ?string
    {
        if ($title === null) {
            return null;
        }

        $translated = __(is_scalar($title) || $title instanceof Stringable ? (string) $title : '', $variables);

        return is_string($translated) ? $translated : '';
    }

    private function imageHeight(?object $image): ?int
    {
        if ($image === null || ! method_exists($image, 'getWidth') || ! method_exists($image, 'getHeight')) {
            return null;
        }

        $sourceWidth = max(1, (int) $image->getWidth());
        $sourceHeight = max(1, (int) $image->getHeight());

        return (int) round(self::IMAGE_WIDTH * ($sourceHeight / $sourceWidth));
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $resolver
     * @return TValue|null
     */
    private function resolve(callable $resolver): mixed
    {
        try {
            return $resolver();
        } catch (Throwable) {
            return null;
        }
    }
}
