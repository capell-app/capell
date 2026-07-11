@php
    use Capell\Admin\Actions\Themes\BuildThemeEditorPreviewAction;
    use Capell\Admin\Actions\Themes\ResolveThemeDefinitionsAction;
    use Capell\Admin\Data\Themes\ThemeEditorContextData;
    use Capell\Core\Models\Theme;

    $read = fn (string $path, mixed $fallback = null): mixed => isset($get) ? ($get($path) ?? $fallback) : $fallback;
    $theme = isset($record) && $record instanceof Theme ? $record : null;
    $previewContext = $theme instanceof Theme
        ? ThemeEditorContextData::forTheme($theme, ResolveThemeDefinitionsAction::run()[$theme->key] ?? null)
        : null;
    $preview = BuildThemeEditorPreviewAction::run([
        'preset' => $read('meta.editor.preset', []),
        'brand' => $read('meta.editor.brand', []),
        'header' => $read('meta.editor.header', []),
        'surface' => $read('meta.editor.surface', []),
        'footer' => $read('meta.editor.footer', []),
        'assets' => $read('meta.editor.assets', []),
        'advanced' => $read('meta.editor.advanced', []),
        'admin' => $read('admin.editor', []),
    ], $previewContext);
    $device = $read('admin.editor.preview.device', 'desktop');
    $colorMode = $read('admin.editor.preview.colorMode', 'light');
    $deviceClass = match ($device) {
        'mobile' => 'max-w-[390px]',
        'tablet' => 'max-w-[768px]',
        default => 'max-w-full',
    };
@endphp

<div class="sticky top-6">
    <div class="mb-3 text-xs text-gray-500 dark:text-gray-400">
        {{
            __('capell-admin::theme-editor.preview.current', [
                'device' => __('capell-admin::theme-editor.options.' . $device),
                'mode' => __('capell-admin::theme-editor.options.' . $colorMode),
            ])
        }}
    </div>
    <div class="flex justify-center">
        <iframe
            class="{{ $deviceClass }} h-[720px] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
            data-preview-device="{{ $device }}"
            data-preview-color-mode="{{ $colorMode }}"
            sandbox
            srcdoc="{{ e($preview->html) }}"
            tabindex="-1"
            title="{{ __('capell-admin::theme-library.actions.preview') }}"
        ></iframe>
    </div>
</div>
