<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Frontend\Contracts\FrontendResponseRenderer;

final class FrontendResponseRendererRegistry
{
    /** @var array<string, class-string<FrontendResponseRenderer>|FrontendResponseRenderer> */
    private array $renderers = [];

    public function register(FrontendResponseRenderer $renderer): void
    {
        $this->renderers[$renderer->runtime()->value] = $renderer;
    }

    /**
     * @param  class-string<FrontendResponseRenderer>  $renderer
     */
    public function registerClass(FrontendRuntime $runtime, string $renderer): void
    {
        $this->renderers[$runtime->value] = $renderer;
    }

    public function forRuntime(FrontendRuntime $runtime): ?FrontendResponseRenderer
    {
        $renderer = $this->renderers[$runtime->value] ?? null;

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
