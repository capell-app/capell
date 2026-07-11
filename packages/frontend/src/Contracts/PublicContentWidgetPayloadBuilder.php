<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRenderContextData;

interface PublicContentWidgetPayloadBuilder
{
    /** Deterministic schema/code fingerprint used by public render caches. */
    public function fingerprint(): string;

    /**
     * Build request-safe, fully hydrated public payloads before Blade rendering.
     *
     * Values must be typed public render objects. Saved widget state must never
     * cross this boundary.
     *
     * @return array<string, object>
     */
    public function build(FrontendRenderContextData $context): array;
}
