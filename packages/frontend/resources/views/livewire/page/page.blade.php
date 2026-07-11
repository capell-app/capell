@php
    use Capell\Core\Models\Theme;
    use Capell\Core\ThemeStudio\Actions\RenderCurrentThemePageAction;
    use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
    use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
    use Capell\Frontend\Contracts\FrontendContextReader;
    use Capell\Frontend\Facades\Frontend;

    $themeStudioRenderer = RenderCurrentThemePageAction::class;
    $themeStudioRegistry = ThemeRegistry::class;
    $themeStudioContext = app()->bound(FrontendContextReader::class)
        ? resolve(FrontendContextReader::class)
        : null;
    $themeStudioTheme = ($theme ?? null) instanceof Theme
        ? $theme
        : ($themeStudioContext?->theme() ?? Frontend::theme());
    $themeStudioSettings = app()->bound(ThemeRuntimeSettings::class)
        ? resolve(ThemeRuntimeSettings::class)
        : null;
    $themeStudioThemeKey = $themeStudioTheme instanceof Theme
        ? $themeStudioTheme->key
        : $themeStudioSettings?->activeTheme();
    $themeStudioPresetKey = $themeStudioTheme instanceof Theme
        ? data_get($themeStudioTheme->meta, 'active_preset', $themeStudioSettings?->activePreset())
        : $themeStudioSettings?->activePreset();

    if (! is_string($themeStudioPresetKey) || $themeStudioPresetKey === '') {
        $themeStudioPresetKey = $themeStudioSettings?->activePreset();
    }

    // Note: unlike BladeFrontendResponseRenderer::themeSlot(), which checks
    // layoutHasContainers($layout) before falling back to hasRenderer($key),
    // this gate only checks hasRenderer(). Container-vs-renderer precedence
    // is therefore only reconciled on the BladeOnly path today. This is an
    // accepted, intentional gap — not something to "fix" here by adding new
    // branching — until themes fully drop renderers in a later phase of this
    // program.
    $themeStudioCanRender =
        class_exists($themeStudioRenderer)
        && class_exists($themeStudioRegistry)
        && app()->bound($themeStudioRegistry)
        && $themeStudioThemeKey !== null
        && resolve($themeStudioRegistry)->hasRenderer($themeStudioThemeKey);
@endphp

<div class="capell-component capell-livewire-page-page">
    @if ($themeStudioCanRender)
        {!! $themeStudioRenderer::run(activeTheme: $themeStudioThemeKey, activePreset: $themeStudioPresetKey) !!}
    @else
        <x-capell::layout class="page-default" />
    @endif
</div>
