<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\View;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Exception;
use Illuminate\View\View;

final class RenderingStrategyViewComposer
{
    public function compose(View $view): void
    {
        if (($view->getData()['runtimeManifest'] ?? null) instanceof FrontendRuntimeManifestData) {
            return;
        }

        try {
            $page = Frontend::page();
        } catch (Exception) {
            $this->withManifest($view, RenderingStrategyEnum::BladeOnly);

            return;
        }

        if (! $page instanceof Pageable) {
            $this->withManifest($view, RenderingStrategyEnum::BladeOnly);

            return;
        }

        $strategy = RenderingStrategyEnum::tryFrom($page->meta['rendering_strategy'] ?? '')
            ?? RenderingStrategyEnum::BladeOnly;

        $this->withManifest($view, $strategy);
    }

    private function withManifest(View $view, RenderingStrategyEnum $strategy): void
    {
        $manifest = FrontendRuntimeManifestData::forRenderingStrategy($strategy);

        $view->with('runtimeManifest', $manifest);
        $view->with('livewireEnabled', $manifest->usesLivewire);
    }
}
