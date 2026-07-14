<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
use Capell\Frontend\Data\Assets\FrontendResourceActivationPlanData;
use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Illuminate\Foundation\Vite;

final class DefaultFrontendResourcePlanRenderer implements FrontendResourcePlanRenderer
{
    public function __construct(private readonly Vite $vite) {}

    public function render(FrontendResourcePlanData $plan, FrontendResourceContextData $context): RenderedFrontendResourcesData
    {
        $head = array_map($this->renderResource(...), $plan->headResources);
        $hints = array_map($this->renderHint(...), $plan->hints);
        $bodyEnd = array_map($this->renderResource(...), $plan->bodyEndResources);

        return new RenderedFrontendResourcesData(
            headHtml: implode(PHP_EOL, array_filter([...$hints, ...$head])),
            bodyEndHtml: implode(PHP_EOL, array_filter($bodyEnd)),
            lazyRuntimePayload: array_map($this->activationPayload(...), $plan->lazyActivationGraphs),
        );
    }

    private function renderResource(ResolvedFrontendResourceData $resource): string
    {
        $attributes = $this->securityAttributes($resource);
        $nonce = $this->vite->cspNonce();
        $nonceAttribute = is_string($nonce) && $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';

        return match ($resource->kind) {
            FrontendResourceKind::Style => '<link rel="stylesheet" href="' . e((string) $resource->url) . '"' . $attributes . '>',
            FrontendResourceKind::ModuleScript => '<script type="module" src="' . e((string) $resource->url) . '"' . ($resource->async ? ' async' : '') . $attributes . $nonceAttribute . '></script>',
            FrontendResourceKind::ClassicScript => '<script src="' . e((string) $resource->url) . '"' . ($resource->defer ? ' defer' : '') . ($resource->async ? ' async' : '') . $attributes . $nonceAttribute . '></script>',
            FrontendResourceKind::InlineStyle => '<style' . $nonceAttribute . '>' . $this->escapeClosingTag((string) $resource->content, 'style') . '</style>',
            FrontendResourceKind::InlineScript => '<script' . $nonceAttribute . '>' . $this->escapeClosingTag((string) $resource->content, 'script') . '</script>',
        };
    }

    private function securityAttributes(ResolvedFrontendResourceData $resource): string
    {
        return implode('', array_filter([
            $resource->integrity !== null ? ' integrity="' . e($resource->integrity) . '"' : null,
            $resource->crossOrigin !== null ? ' crossorigin="' . $resource->crossOrigin->value . '"' : null,
            $resource->referrerPolicy !== null ? ' referrerpolicy="' . $resource->referrerPolicy->value . '"' : null,
        ]));
    }

    private function renderHint(FrontendResourceHintData $hint): string
    {
        return '<link rel="' . $hint->kind->value . '" href="' . e($hint->href) . '"'
            . ($hint->as !== null ? ' as="' . $hint->as->value . '"' : '')
            . ($hint->mimeType !== null ? ' type="' . e($hint->mimeType) . '"' : '')
            . ($hint->crossOrigin !== null ? ' crossorigin="' . $hint->crossOrigin->value . '"' : '')
            . ($hint->fetchPriority !== null ? ' fetchpriority="' . $hint->fetchPriority->value . '"' : '')
            . '>';
    }

    /** @return array<string, mixed> */
    private function activationPayload(FrontendResourceActivationPlanData $activation): array
    {
        return [
            'target' => $activation->target,
            'loading' => $activation->loadingStrategy->value,
            'layers' => array_map(
                fn (array $layer): array => array_map($this->publicResourcePayload(...), $layer),
                $activation->dependencyLayers,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function publicResourcePayload(ResolvedFrontendResourceData $resource): array
    {
        return array_filter([
            'token' => $resource->token,
            'kind' => $resource->kind->value,
            'url' => $resource->url,
            'content' => $resource->content,
            'integrity' => $resource->integrity,
            'crossorigin' => $resource->crossOrigin?->value,
            'referrerPolicy' => $resource->referrerPolicy?->value,
            'defer' => $resource->defer,
            'async' => $resource->async,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function escapeClosingTag(string $content, string $tag): string
    {
        return preg_replace('#</' . $tag . '#i', '<\\/' . $tag, $content) ?? $content;
    }
}
