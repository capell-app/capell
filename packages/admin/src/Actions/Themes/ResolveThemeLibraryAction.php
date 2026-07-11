<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Contracts\Themes\PendingThemeInstallProvider;
use Capell\Admin\Data\Themes\ThemeLibraryCardData;
use Capell\Admin\Support\Themes\ThemeLibraryRuntime;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static array{installed: list<ThemeLibraryCardData>, available: list<ThemeLibraryCardData>, pending: int, pendingInstalls: list<array{name: string, package: string, command: string}>, warnings: list<ThemeLibraryCardData>} run()
 */
final class ResolveThemeLibraryAction
{
    use AsAction;

    /**
     * @return array{installed: list<ThemeLibraryCardData>, available: list<ThemeLibraryCardData>, pending: int, pendingInstalls: list<array{name: string, package: string, command: string}>, warnings: list<ThemeLibraryCardData>}
     */
    public function handle(): array
    {
        $runtime = resolve(ThemeLibraryRuntime::class);
        $definitions = $runtime->definitions();
        $installedThemes = Theme::query()
            ->with('media')
            ->withCount('sites')
            ->ordered()
            ->get()
            ->reject(fn (Theme $theme): bool => $this->isUnusedLegacyFoundationTheme($theme, $definitions));

        $installed = $installedThemes
            ->toBase()
            ->map(fn (Theme $theme): ThemeLibraryCardData => $runtime->installedCard($theme))
            ->values();

        $installedThemeKeys = $installedThemes->pluck('key')->all();
        $available = collect($definitions)
            ->reject(fn (ThemeDefinitionData $definition): bool => in_array($definition->key, $installedThemeKeys, true))
            ->map(fn (ThemeDefinitionData $definition): ThemeLibraryCardData => $runtime->availableCard($definition))
            ->values();

        $pendingInstalls = $this->pendingInstalls();

        return [
            'installed' => array_values($installed->all()),
            'available' => array_values($available->all()),
            'pending' => count($pendingInstalls),
            'pendingInstalls' => $pendingInstalls,
            'warnings' => array_values($installed
                ->merge($available)
                ->filter(fn (ThemeLibraryCardData $card): bool => $card->diagnostics->hasWarnings())
                ->values()
                ->all()),
        ];
    }

    /**
     * @return list<array{name: string, package: string, command: string}>
     */
    private function pendingInstalls(): array
    {
        return array_values(collect(app()->tagged(PendingThemeInstallProvider::TAG))
            ->filter(fn (mixed $provider): bool => $provider instanceof PendingThemeInstallProvider)
            ->flatMap(fn (PendingThemeInstallProvider $provider): array => $provider->pendingThemeInstalls())
            ->take(5)
            ->values()
            ->all());
    }

    /**
     * Foundation used to be stored with a `foundation` theme key. The runtime
     * definition is now the registered `default` theme, so an unused legacy row
     * should not make `capell:themes:validate` fail.
     *
     * @param  array<string, ThemeDefinitionData>  $definitions
     */
    private function isUnusedLegacyFoundationTheme(Theme $theme, array $definitions): bool
    {
        $foundationDefinition = $definitions['default'] ?? null;

        return $theme->key === 'foundation'
            && (int) ($theme->sites_count ?? 0) === 0
            && $foundationDefinition instanceof ThemeDefinitionData
            && $foundationDefinition->package === 'capell-app/foundation-theme';
    }
}
