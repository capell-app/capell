<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Enums\CacheTime;
use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
use Capell\Frontend\Data\Assets\FrontendResourceActivationPlanData;
use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Enums\CrossOrigin;
use Capell\Frontend\Enums\FetchPriority;
use Capell\Frontend\Enums\FrontendResourceHintAs;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\ReferrerPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;

final class DefaultFrontendResourcePlanRenderer implements FrontendResourcePlanRenderer
{
    public function __construct(
        private readonly Vite $vite,
        private readonly StylesheetRecoveryRenderer $stylesheetRecovery,
    ) {}

    public function render(FrontendResourcePlanData $plan, FrontendResourceContextData $context): RenderedFrontendResourcesData
    {
        // HTML that may be cached or exported is replayed to many visitors, so
        // a per-response nonce would be stale. Such pages carry no nonces; each
        // inline script and style is authorised by a content hash instead.
        $useNonce = $this->responseIsNeverCached($context);
        $scriptHashes = [];
        $styleHashes = [];
        $renderResource = function (ResolvedFrontendResourceData $resource) use ($useNonce, &$scriptHashes, &$styleHashes): string {
            $html = $this->renderResource($resource, $useNonce);
            $inline = $this->inlineContent($resource);

            if ($inline !== null) {
                $hash = "'sha256-" . base64_encode(hash('sha256', $inline, true)) . "'";

                if ($resource->kind === FrontendResourceKind::InlineStyle) {
                    $styleHashes[$hash] = $hash;
                } else {
                    $scriptHashes[$hash] = $hash;
                }
            }

            return $html;
        };
        $head = array_map($renderResource, $plan->headResources);
        $hints = array_map($this->renderHint(...), $plan->hints);
        $bodyEnd = array_map($renderResource, $plan->bodyEndResources);
        $recovery = $this->requiresStylesheetRecovery($plan) ? $this->stylesheetRecovery->cspHashSource() : null;

        if ($recovery !== null) {
            $scriptHashes[$recovery] = $recovery;
        }

        return new RenderedFrontendResourcesData(
            headHtml: implode(PHP_EOL, array_filter([
                ...$hints,
                $this->requiresStylesheetRecovery($plan) ? $this->stylesheetRecovery->runtimeTag($useNonce) : '',
                ...$head,
            ])),
            bodyEndHtml: implode(PHP_EOL, array_filter($bodyEnd)),
            lazyRuntimePayload: array_map($this->activationPayload(...), $plan->lazyActivationGraphs),
            scriptHashSources: array_values($scriptHashes),
            styleHashSources: array_values($styleHashes),
        );
    }

    /**
     * A nonce is only safe where the HTML is certainly not stored. The HTML
     * cache decides on request state this renderer cannot see, so a page counts
     * as uncached only when its blueprint is loaded and says never to cache it
     * (or there is no page at all). Anything else is treated as cacheable.
     */
    private function responseIsNeverCached(FrontendResourceContextData $context): bool
    {
        $page = $context->page;

        if (! $page instanceof Model) {
            return true;
        }

        return $page->relationLoaded('blueprint') && $page->blueprint?->cache_time === CacheTime::Never;
    }

    private function inlineContent(ResolvedFrontendResourceData $resource): ?string
    {
        return match ($resource->kind) {
            FrontendResourceKind::InlineStyle => $this->escapeClosingTag((string) $resource->content, 'style'),
            FrontendResourceKind::InlineScript => $this->escapeClosingTag((string) $resource->content, 'script'),
            default => null,
        };
    }

    private function renderResource(ResolvedFrontendResourceData $resource, bool $useNonce): string
    {
        $attributes = $this->securityAttributes($resource);
        $nonce = $useNonce ? $this->vite->cspNonce() : null;
        $nonceAttribute = is_string($nonce) && $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';

        return match ($resource->kind) {
            FrontendResourceKind::Style => '<link rel="stylesheet" href="' . e((string) $resource->url) . '"' . $attributes . ($resource->criticalCssEligible ? $this->stylesheetRecovery->linkAttributes() : '') . '>',
            FrontendResourceKind::ModuleScript => '<script type="module" src="' . e((string) $resource->url) . '"' . ($resource->async ? ' async' : '') . $attributes . $nonceAttribute . '></script>',
            FrontendResourceKind::ClassicScript => '<script src="' . e((string) $resource->url) . '"' . ($resource->defer ? ' defer' : '') . ($resource->async ? ' async' : '') . $attributes . $nonceAttribute . '></script>',
            FrontendResourceKind::InlineStyle => '<style' . $nonceAttribute . '>' . $this->escapeClosingTag((string) $resource->content, 'style') . '</style>',
            FrontendResourceKind::InlineScript => '<script' . $nonceAttribute . '>' . $this->escapeClosingTag((string) $resource->content, 'script') . '</script>',
        };
    }

    private function requiresStylesheetRecovery(FrontendResourcePlanData $plan): bool
    {
        if (! $this->stylesheetRecovery->enabled()) {
            return false;
        }

        return array_any(
            $plan->headResources,
            static fn (ResolvedFrontendResourceData $resource): bool => $resource->kind === FrontendResourceKind::Style
                && $resource->criticalCssEligible,
        );
    }

    private function securityAttributes(ResolvedFrontendResourceData $resource): string
    {
        return implode('', array_filter([
            $resource->integrity !== null ? ' integrity="' . e($resource->integrity) . '"' : null,
            $resource->crossOrigin instanceof CrossOrigin ? ' crossorigin="' . $resource->crossOrigin->value . '"' : null,
            $resource->referrerPolicy instanceof ReferrerPolicy ? ' referrerpolicy="' . $resource->referrerPolicy->value . '"' : null,
        ]));
    }

    private function renderHint(FrontendResourceHintData $hint): string
    {
        return '<link rel="' . $hint->kind->value . '" href="' . e($hint->href) . '"'
            . ($hint->as instanceof FrontendResourceHintAs ? ' as="' . $hint->as->value . '"' : '')
            . ($hint->mimeType !== null ? ' type="' . e($hint->mimeType) . '"' : '')
            . ($hint->crossOrigin instanceof CrossOrigin ? ' crossorigin="' . $hint->crossOrigin->value . '"' : '')
            . ($hint->fetchPriority instanceof FetchPriority ? ' fetchpriority="' . $hint->fetchPriority->value . '"' : '')
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
