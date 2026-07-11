<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Data\Themes\ThemeEditorPreviewData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Admin\Support\Themes\ThemeEditorExtensionRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static ThemeEditorPreviewData run(ThemeEditorStateData|array<string, mixed> $state, ?ThemeEditorContextData $context = null)
 */
final class BuildThemeEditorPreviewAction
{
    use AsAction;

    /**
     * @param  ThemeEditorStateData|array<string, mixed>  $state
     */
    public function handle(ThemeEditorStateData|array $state, ?ThemeEditorContextData $context = null): ThemeEditorPreviewData
    {
        $state = $state instanceof ThemeEditorStateData
            ? $state
            : $this->stateFromArray($state);
        $extensions = $context instanceof ThemeEditorContextData
            ? resolve(ThemeEditorExtensionRegistry::class)->forContext($context)
            : collect();
        $cssVariables = $this->cssVariables($state, $context, $extensions);
        $dataAttributes = $this->dataAttributes($state, $context, $extensions);
        $sampleContent = $this->sampleContent($context, $extensions);
        $html = $this->previewComponentHtml($state, $context, $extensions, $cssVariables, $dataAttributes, $sampleContent)
            ?? $this->html($state, $cssVariables, $dataAttributes, $sampleContent);

        return new ThemeEditorPreviewData(
            html: $html,
            cssVariables: $cssVariables,
            dataAttributes: $dataAttributes,
        );
    }

    /**
     * @param  Collection<int, ThemeEditorExtension>  $extensions
     * @return array<string, string>
     */
    private function cssVariables(ThemeEditorStateData $state, ?ThemeEditorContextData $context, Collection $extensions): array
    {
        $variables = [
            '--theme-primary' => (string) $state->brand['primaryColor'],
            '--theme-accent' => (string) $state->brand['accentColor'],
            '--theme-neutral' => (string) $state->brand['neutralColor'],
            '--theme-surface' => (string) $state->surface['surfaceColor'],
            '--theme-foreground' => (string) $state->surface['foregroundColor'],
            '--theme-radius' => $this->radius((string) $state->brand['radius']),
        ];

        if (! $context instanceof ThemeEditorContextData) {
            return $variables;
        }

        foreach ($extensions as $extension) {
            $variables = [
                ...$variables,
                ...$extension->cssVariables($state, $context),
            ];
        }

        return collect($variables)
            ->filter(fn (string $value, string $key): bool => preg_match('/^--[a-z0-9-]+$/i', $key) === 1)
            ->all();
    }

    /**
     * @param  Collection<int, ThemeEditorExtension>  $extensions
     * @return array<string, string>
     */
    private function dataAttributes(ThemeEditorStateData $state, ?ThemeEditorContextData $context, Collection $extensions): array
    {
        $attributes = [
            'data-header-position' => (string) $state->header['position'],
            'data-container' => (string) $state->surface['container'],
            'data-heading-scale' => (string) $state->surface['headingScale'],
            'data-card-density' => (string) $state->surface['cardDensity'],
            'data-color-mode' => (string) data_get($state->admin, 'preview.colorMode', 'light'),
        ];

        if (! $context instanceof ThemeEditorContextData) {
            return $attributes;
        }

        foreach ($extensions as $extension) {
            $attributes = [
                ...$attributes,
                ...$extension->dataAttributes($state, $context),
            ];
        }

        return collect($attributes)
            ->filter(fn (string $value, string $key): bool => preg_match('/^data-[a-z0-9-]+$/i', $key) === 1)
            ->all();
    }

    /**
     * @param  array<string, string>  $cssVariables
     * @param  array<string, string>  $dataAttributes
     * @param  array<string, string>  $sampleContent
     */
    private function html(ThemeEditorStateData $state, array $cssVariables, array $dataAttributes, array $sampleContent): string
    {
        $variables = collect($cssVariables)
            ->map(fn (string $value, string $key): string => sprintf('%s:%s;', $key, e($value)))
            ->implode('');
        $attributes = collect($dataAttributes)
            ->map(fn (string $value, string $key): string => sprintf('%s="%s"', $key, e($value)))
            ->implode(' ');
        $customCss = $this->sanitizeCustomCss((string) ($state->advanced['customCss'] ?? ''));
        $headline = e($sampleContent['headline'] ?? 'Shape the site system before saving');
        $body = e($sampleContent['body'] ?? 'Preview brand, surface, header, footer, and advanced CSS changes from the current editor state.');
        $headerPosition = $this->headerPosition((string) ($state->header['position'] ?? 'sticky'));
        $header = (bool) ($state->header['enabled'] ?? true)
            ? '<header class="theme-preview__header"><div class="theme-preview__brand">Theme preview</div></header>'
            : '';
        $footerCopy = e((string) ($state->footer['copy'] ?? 'Sandboxed theme editor preview'));
        $footer = (bool) ($state->footer['enabled'] ?? true)
            ? '<footer class="theme-preview__footer">' . $footerCopy . '</footer>'
            : '';

        return (string) new HtmlString(<<<HTML
<!doctype html>
<html lang="en" {$attributes}>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {{$variables}}
html[data-color-mode="dark"]{--theme-surface:#111827;--theme-foreground:#f9fafb;--theme-neutral:#f9fafb;}
body{margin:0;background:var(--theme-surface);color:var(--theme-foreground);font-family:Inter,ui-sans-serif,system-ui,sans-serif;}
.theme-preview{min-height:100vh;}
.theme-preview__header{position:{$headerPosition};top:0;padding:18px 28px;background:color-mix(in srgb,var(--theme-surface) 92%,white);border-bottom:1px solid color-mix(in srgb,var(--theme-neutral) 14%,transparent);}
.theme-preview__brand{font-weight:750;color:var(--theme-neutral);}
.theme-preview__hero{padding:56px 28px 44px;max-width:960px;margin:0 auto;}
.theme-preview__hero h1{font-size:42px;line-height:1.05;margin:0 0 16px;color:var(--theme-neutral);}
.theme-preview__hero p{font-size:18px;line-height:1.6;max-width:660px;margin:0 0 24px;}
.theme-preview__button{display:inline-flex;padding:12px 18px;border-radius:var(--theme-radius);background:var(--theme-primary);color:white;font-weight:700;text-decoration:none;}
.theme-preview__cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;max-width:960px;margin:0 auto;padding:0 28px 48px;}
.theme-preview__card{border:1px solid color-mix(in srgb,var(--theme-neutral) 12%,transparent);border-radius:var(--theme-radius);padding:18px;background:white;}
.theme-preview__footer{padding:24px 28px;border-top:1px solid color-mix(in srgb,var(--theme-neutral) 14%,transparent);color:color-mix(in srgb,var(--theme-foreground) 70%,transparent);}
{$customCss}
</style>
</head>
<body>
<main class="theme-preview">
{$header}
<section class="theme-preview__hero">
<h1>{$headline}</h1>
<p>{$body}</p>
<a class="theme-preview__button" href="#" tabindex="-1" aria-hidden="true">Primary action</a>
</section>
<section class="theme-preview__cards">
<article class="theme-preview__card"><strong>Brand</strong><p>Primary, accent, typography, and radius tokens.</p></article>
<article class="theme-preview__card"><strong>Surface</strong><p>Container, density, foreground, and page colour.</p></article>
<article class="theme-preview__card"><strong>Chrome</strong><p>Header and footer choices in context.</p></article>
</section>
{$footer}
</main>
</body>
</html>
HTML);
    }

    private function radius(string $radius): string
    {
        return match ($radius) {
            'none' => '0',
            'sm' => '0.25rem',
            'lg' => '0.75rem',
            'xl' => '1rem',
            default => '0.5rem',
        };
    }

    /**
     * @param  Collection<int, ThemeEditorExtension>  $extensions
     * @return array<string, string>
     */
    private function sampleContent(?ThemeEditorContextData $context, Collection $extensions): array
    {
        if (! $context instanceof ThemeEditorContextData) {
            return [];
        }

        $content = [];

        foreach ($extensions as $extension) {
            $content = [
                ...$content,
                ...$extension->samplePreviewContent($context),
            ];
        }

        return collect($content)
            ->mapWithKeys(fn (mixed $value, string $key): array => [$key => is_scalar($value) ? (string) $value : ''])
            ->all();
    }

    /**
     * @param  Collection<int, ThemeEditorExtension>  $extensions
     * @param  array<string, string>  $cssVariables
     * @param  array<string, string>  $dataAttributes
     * @param  array<string, string>  $sampleContent
     */
    private function previewComponentHtml(
        ThemeEditorStateData $state,
        ?ThemeEditorContextData $context,
        Collection $extensions,
        array $cssVariables,
        array $dataAttributes,
        array $sampleContent,
    ): ?string {
        if (! $context instanceof ThemeEditorContextData) {
            return null;
        }

        foreach ($extensions as $extension) {
            $view = $extension->previewComponent($context);
            if (! is_string($view)) {
                continue;
            }

            if ($view === '') {
                continue;
            }

            if (! view()->exists($view)) {
                continue;
            }

            return view($view, [
                'context' => $context,
                'state' => $state,
                'cssVariables' => $cssVariables,
                'dataAttributes' => $dataAttributes,
                'sampleContent' => $sampleContent,
            ])->render();
        }

        return null;
    }

    private function headerPosition(string $position): string
    {
        return in_array($position, ['static', 'sticky', 'fixed'], true) ? $position : 'sticky';
    }

    private function sanitizeCustomCss(string $css): string
    {
        return str_ireplace('</style', '<\\/style', $css);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function stateFromArray(array $state): ThemeEditorStateData
    {
        $defaults = ThemeEditorStateData::defaults();

        return new ThemeEditorStateData(
            preset: [...$defaults->preset, ...(is_array($state['preset'] ?? null) ? $state['preset'] : [])],
            brand: [...$defaults->brand, ...(is_array($state['brand'] ?? null) ? $state['brand'] : [])],
            header: [...$defaults->header, ...(is_array($state['header'] ?? null) ? $state['header'] : [])],
            surface: [...$defaults->surface, ...(is_array($state['surface'] ?? null) ? $state['surface'] : [])],
            footer: [...$defaults->footer, ...(is_array($state['footer'] ?? null) ? $state['footer'] : [])],
            assets: [...$defaults->assets, ...(is_array($state['assets'] ?? null) ? $state['assets'] : [])],
            advanced: [...$defaults->advanced, ...(is_array($state['advanced'] ?? null) ? $state['advanced'] : [])],
            admin: [...$defaults->admin, ...(is_array($state['admin'] ?? null) ? $state['admin'] : [])],
        );
    }
}
