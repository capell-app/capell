<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static array<string, ThemeDefinitionData> run()
 */
final class ResolveThemeDefinitionsAction
{
    use AsAction;

    /**
     * @return array<string, ThemeDefinitionData>
     */
    public function handle(): array
    {
        $definitions = [];

        if (app()->bound(ThemeRegistry::class)) {
            $definitions = resolve(ThemeRegistry::class)->definitions();
        }

        if (app()->bound(LocalAppThemeDefinitionRepository::class)) {
            $definitions = [
                ...resolve(LocalAppThemeDefinitionRepository::class)->all(),
                ...$definitions,
            ];
        }

        ksort($definitions);

        return $definitions;
    }
}
