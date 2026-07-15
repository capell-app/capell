@php
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
    use Capell\Frontend\Support\Security\JsonLdScriptSanitizer;

    $site = Frontend::site();
    $siteMeta = $site?->meta ?? [];
    $metaSchema = data_get($siteMeta, 'meta_schema');
    $customMetaSchema = data_get($siteMeta, 'custom_meta_schema');
    $runtimeManifest ??= null;
    $usesLivewire = $runtimeManifest?->usesLivewire ?? ($livewireEnabled ?? false);
@endphp

<!DOCTYPE html>
<html
    class="h-full"
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
>
    <x-capell::app.head
        :livewire-enabled="$usesLivewire"
        :runtime-manifest="$runtimeManifest"
        :resource-plan="$resourcePlan ?? null"
    />

    <x-capell::app.body
        :layout="$layout"
        :language="$language"
        :page-record="$pageRecord"
        :site="$site"
        :theme="$theme"
    >
        {{ $slot }}

        @php($renderedFrontendResources = Frontend::getFrontendData('renderedFrontendResources'))
        @if ($renderedFrontendResources instanceof RenderedFrontendResourcesData)
            {!! $renderedFrontendResources->bodyEndHtml !!}

            @if ($renderedFrontendResources->lazyRuntimePayload !== [])
                <script
                    type="application/json"
                    data-capell-widget-assets
                >
                    {!! json_encode($renderedFrontendResources->lazyRuntimePayload, JSON_THROW_ON_ERROR) !!}
                </script>
            @endif
        @endif

        {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::BodyEnd) !!}

        @stack('scripts')

        @yield('scripts')

        @if ($usesLivewire)
            @livewireScripts
        @endif

        @if ($metaSchema)
            @foreach ($metaSchema as $schema)
                <x-dynamic-component :component="$schema" />
            @endforeach
        @endif

        @if ($customMetaSchema)
            <script type="application/ld+json">
                {!! JsonLdScriptSanitizer::sanitize((string) $customMetaSchema) !!}
            </script>
        @endif
    </x-capell::app.body>
</html>
