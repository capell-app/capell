<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\ContentStructure;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Contracts\HtmlMinifier;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string|array<int|string, mixed> run(null|string|array<int|string, mixed> $content, ?ContentStructure $structure = null, array<string, mixed> $context = [], bool $stripTags = false, bool $decodeEntities = false, bool $asArray = false)
 */
class RenderContentAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int|string, mixed>|string|null  $content
     * @param  array<string, mixed>  $context
     * @return string|array<int|string, mixed>
     */
    public function handle(null|string|array $content, ?ContentStructure $structure = null, array $context = [], bool $stripTags = false, bool $decodeEntities = false, bool $asArray = false): string|array
    {
        if ($content === null || $content === '' || (is_array($content) && $content === [])) {
            return $asArray ? [] : '';
        }

        $html = match ($structure) {
            ContentStructure::Blocks => $this->renderWidgets($content, $context),
            default => $this->renderHtml($content, $context),
        };

        if ($stripTags) {
            $html = strip_tags($html);
        }

        if (resolve(FrontendSettingsReaderInterface::class)->minifyHtml()) {
            $html = resolve(HtmlMinifier::class)->minify($html);
        }

        if ($decodeEntities) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($asArray) {
            return HtmlToArrayAction::run($html);
        }

        return $html;
    }

    /**
     * @param  array<int|string, mixed>  $content
     * @param  array<string, mixed>  $context
     */
    private function renderWidgets(array $content, array $context): string
    {
        /** @var view-string $widgetsView */
        $widgetsView = 'capell-layout-builder::components.layout-widgets.index';

        if (! view()->exists($widgetsView)) {
            return '';
        }

        return view($widgetsView, ['widgets' => $content, ...$context])->render();
    }

    /** @param array<string, mixed> $context */
    private function renderHtml(string $content, array $context): string
    {
        return RenderHtmlContentAction::run($content, $context)->toHtml();
    }
}
