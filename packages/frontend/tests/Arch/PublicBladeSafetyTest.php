<?php

declare(strict_types=1);

it('keeps public frontend blade views free of query and authoring patterns', function (): void {
    $violations = [];

    foreach (publicFrontendBladePaths() as $path) {
        $contents = (string) file_get_contents($path);
        $relativePath = str_replace(dirname(__DIR__, 4) . '/', '', $path);

        foreach (publicFrontendBladeForbiddenPatterns() as $label => $pattern) {
            if (preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $violations[] = sprintf('%s: %s', $relativePath, $label);
        }
    }

    expect($violations)->toBe([]);
});

it('documents the allowed public frontend runtime data attributes', function (): void {
    $allowedAttributes = publicFrontendBladeAllowedRuntimeAttributes();

    expect($allowedAttributes)->toBe([
        'data-capell-widget-assets',
        'data-capell-widget-runtime',
        'data-capell-widget-settings',
        'data-capell-widget-resources',
        'data-capell-interaction',
        'data-capell-interaction-id',
        'data-capell-interaction-event',
        'data-capell-interaction-behavior',
        'data-capell-interaction-target-url',
        'data-capell-interaction-target-type',
        'data-capell-interaction-icon',
        'data-capell-interaction-modal-size',
        'data-capell-interaction-analytics',
        'data-capell-interaction-fallback-url',
        'data-capell-interaction-close-on-backdrop',
        'data-capell-interaction-close-label',
        'data-capell-interaction-loading-label',
        'data-capell-interaction-ready-label',
        'data-capell-interaction-error-label',
        'data-capell-interaction-asset-error-label',
        'data-capell-interaction-retry-label',
        'data-capell-interaction-fallback-label',
        'data-capell-interaction-status',
    ]);
});

it('uses only documented public frontend runtime data attributes', function (): void {
    $allowedAttributes = publicFrontendBladeAllowedRuntimeAttributes();
    $violations = [];

    foreach (publicFrontendBladePaths() as $path) {
        $contents = (string) file_get_contents($path);
        $relativePath = str_replace(dirname(__DIR__, 4) . '/', '', $path);

        preg_match_all('/\bdata-capell-[a-z0-9-]+/', $contents, $matches);

        foreach (array_unique($matches[0]) as $attribute) {
            if (in_array($attribute, $allowedAttributes, true)) {
                continue;
            }

            $violations[] = sprintf('%s: %s', $relativePath, $attribute);
        }
    }

    sort($violations);

    expect($violations)->toBe([]);
});

/**
 * @return list<string>
 */
function publicFrontendBladePaths(): array
{
    $root = dirname(__DIR__, 2) . '/resources/views';
    $paths = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }

        if (! str_ends_with((string) $file->getFilename(), '.blade.php')) {
            continue;
        }

        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

/**
 * @return array<string, string>
 */
function publicFrontendBladeForbiddenPatterns(): array
{
    $authoringMarkers = [
        'data-capell-authoring',
        'data-capell-editable',
        'data-capell-editor',
        'data-capell-editor-url',
        'data-field-path',
        'data-model-id',
        'data-permission',
        'data-capell-package',
        'field_path',
        'model_id',
        'editor_url',
        'signed_editor_url',
        'signed_admin_url',
        'signedEditorUrl',
        'signedAdminUrl',
        'capell-authoring',
        'capell-editor',
    ];

    return [
        'unregistered buffer directive in public Blade' => '/@(?:end)?capellBuffer\b/',
        'Livewire mount directive in public Blade' => '/(?:<livewire:|@livewire\s*\()/i',
        'authored critical CSS partial in public Blade' => '/(?:@include(?:If|When)?\s*\([^)]*critical[-_. ]?css|<x-[^>\s]*critical[-_.]?css)/i',
        'Eloquent query builder in Blade' => '/::query\s*\(/',
        'DB facade in Blade' => '/\bDB::/',
        'auth access in public Blade' => '/\bauth\s*\(/',
        'lazy relationship loading in Blade' => '/->load(?:Missing)?\s*\(/',
        'direct model lookup in Blade' => '/::(?:find|findOrFail|first|firstOrFail)\s*\(/',
        'authoring marker in public Blade' => '/(?:' . implode('|', array_map(
            fn (string $marker): string => preg_quote($marker, '/'),
            $authoringMarkers,
        )) . ')/i',
    ];
}

/**
 * @return list<string>
 */
function publicFrontendBladeAllowedRuntimeAttributes(): array
{
    return [
        'data-capell-widget-assets',
        'data-capell-widget-runtime',
        'data-capell-widget-settings',
        'data-capell-widget-resources',
        'data-capell-interaction',
        'data-capell-interaction-id',
        'data-capell-interaction-event',
        'data-capell-interaction-behavior',
        'data-capell-interaction-target-url',
        'data-capell-interaction-target-type',
        'data-capell-interaction-icon',
        'data-capell-interaction-modal-size',
        'data-capell-interaction-analytics',
        'data-capell-interaction-fallback-url',
        'data-capell-interaction-close-on-backdrop',
        'data-capell-interaction-close-label',
        'data-capell-interaction-loading-label',
        'data-capell-interaction-ready-label',
        'data-capell-interaction-error-label',
        'data-capell-interaction-asset-error-label',
        'data-capell-interaction-retry-label',
        'data-capell-interaction-fallback-label',
        'data-capell-interaction-status',
    ];
}
