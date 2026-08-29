<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Contracts\FrontendResponseRenderer;

/** @extends AbstractKeyedRegistry<class-string<FrontendResponseRenderer>|FrontendResponseRenderer> */
final class FrontendResponseRendererRegistry extends AbstractKeyedRegistry
{
    public function register(FrontendResponseRenderer $renderer): void
    {
        $this->setItem($renderer->runtime()->value, $renderer);
        $this->receipts()?->recordContribution(
            ExtensionContributionType::Asset,
            'response-renderer:' . $renderer->runtime()->value,
            $renderer::class,
            self::class,
            'frontend',
        );
    }

    /**
     * @param  class-string<FrontendResponseRenderer>  $renderer
     */
    public function registerClass(FrontendRuntime $runtime, string $renderer): void
    {
        $this->setItem($runtime->value, $renderer);
        $this->receipts()?->recordContribution(
            ExtensionContributionType::Asset,
            'response-renderer:' . $runtime->value,
            $renderer,
            self::class,
            'frontend',
        );
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

    private function receipts(): ?RecordsExtensionContributionReceipt
    {
        return app()->bound(RecordsExtensionContributionReceipt::class)
            ? resolve(RecordsExtensionContributionReceipt::class)
            : null;
    }
}
