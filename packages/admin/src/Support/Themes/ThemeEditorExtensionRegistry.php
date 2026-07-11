<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Themes;

use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Illuminate\Support\Collection;

final class ThemeEditorExtensionRegistry
{
    /**
     * @return Collection<int, ThemeEditorExtension>
     */
    public function forContext(ThemeEditorContextData $context): Collection
    {
        return collect(app()->tagged(ThemeEditorExtension::TAG))
            ->filter(fn (mixed $extension): bool => $extension instanceof ThemeEditorExtension)
            ->filter(fn (ThemeEditorExtension $extension): bool => $extension->supports($context))
            ->values();
    }
}
