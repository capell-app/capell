<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Themes;

use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;

/** @extends TaggedProviderRegistry<ThemeEditorExtension> */
final class ThemeEditorExtensionRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct($application->tagged(ThemeEditorExtension::TAG), ThemeEditorExtension::class);
    }

    /**
     * @return Collection<int, ThemeEditorExtension>
     */
    public function forContext(ThemeEditorContextData $context): Collection
    {
        return collect($this->providers())
            ->filter(fn (ThemeEditorExtension $extension): bool => $extension->supports($context))
            ->values();
    }
}
