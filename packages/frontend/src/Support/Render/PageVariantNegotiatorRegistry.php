<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Frontend\Contracts\PageVariantNegotiator;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PageVariantNegotiatorRegistry
{
    public const string REQUESTED_VARIANT_ATTRIBUTE = 'capell.frontend.page_variant_requested';

    /** @var list<PageVariantNegotiator> */
    private array $negotiators = [];

    private bool $taggedNegotiatorsDiscovered = false;

    public function __construct(private readonly Container $container) {}

    public function register(PageVariantNegotiator $negotiator): void
    {
        $this->negotiators[] = $negotiator;
    }

    public function resolutionPath(Request $request, string $path): string
    {
        if (! $request->isMethod('GET') || ! str_ends_with($path, '.md') || ! $this->hasNegotiators()) {
            return $path;
        }

        $request->attributes->set(self::REQUESTED_VARIANT_ATTRIBUTE, true);

        return substr($path, 0, -3);
    }

    public function wasVariantRequested(Request $request): bool
    {
        return $request->attributes->get(self::REQUESTED_VARIANT_ATTRIBUTE) === true;
    }

    public function negotiate(Request $request, FrontendRenderContextData $context): ?Response
    {
        if (! $request->isMethod('GET')) {
            return null;
        }

        $this->discoverTaggedNegotiators();

        foreach ($this->negotiators as $negotiator) {
            $response = $negotiator->variant($request, $context);

            if ($response instanceof Response) {
                return $response;
            }
        }

        return null;
    }

    private function hasNegotiators(): bool
    {
        $this->discoverTaggedNegotiators();

        return $this->negotiators !== [];
    }

    private function discoverTaggedNegotiators(): void
    {
        if ($this->taggedNegotiatorsDiscovered) {
            return;
        }

        $this->taggedNegotiatorsDiscovered = true;

        foreach ($this->container->tagged(PageVariantNegotiator::TAG) as $negotiator) {
            if ($negotiator instanceof PageVariantNegotiator) {
                $this->register($negotiator);
            }
        }
    }
}
