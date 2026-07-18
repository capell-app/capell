<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Contracts\FrontendResponseRenderer;

/** @extends AbstractKeyedRegistry<class-string<FrontendResponseRenderer>|FrontendResponseRenderer> */
final class FrontendResponseRendererRegistry extends AbstractKeyedRegistry
{
    public function register(FrontendResponseRenderer $renderer): void
    {
        $this->setItem($renderer->runtime()->value, $renderer);
    }

    /**
     * @param  class-string<FrontendResponseRenderer>  $renderer
     */
    public function registerClass(FrontendRuntime $runtime, string $renderer): void
    {
        $this->setItem($runtime->value, $renderer);
    }

    public function forRuntime(FrontendRuntime $runtime): ?FrontendResponseRenderer
    {
        $renderer = $this->getItem($runtime->value);

        if (is_string($renderer)) {
            return resolve($renderer);
        }

        return $renderer;
    }

    public function has(FrontendRuntime $runtime): bool
    {
        return $this->forRuntime($runtime) instanceof FrontendResponseRenderer;
    }
}
