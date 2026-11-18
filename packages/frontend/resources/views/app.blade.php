@php
    use Capell\Frontend\Actions\BuildPublicDocumentLanguageDataAction;
    use Capell\Frontend\Actions\BuildPublicSiteSchemaDataAction;
    use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
    use Capell\Frontend\Support\Render\HeadMetadataCollector;
    use Capell\Frontend\Support\Security\JsonLdScriptSanitizer;

    $documentLanguage = BuildPublicDocumentLanguageDataAction::run($language ?? null);

    $siteSchema = BuildPublicSiteSchemaDataAction::run();
    $runtimeManifest ??= null;
    $usesLivewire = $runtimeManifest?->usesLivewire ?? ($livewireEnabled ?? false);
@endphp

<!DOCTYPE html>
<html
    class="h-full"
    lang="{{ $documentLanguage->code }}"
    dir="{{ $documentLanguage->direction }}"
>
    <x-capell::app.head
        :livewire-enabled="$usesLivewire"
        :runtime-manifest="$runtimeManifest"
        :resource-plan="$resourcePlan ?? null"
    />

    @php(ob_start())
    <x-capell::app.body
        body-class="capell-default-theme"
        :layout="$layout"
        :language="$language"
        :page-record="$pageRecord"
        :site="$site ?? null"
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

        @foreach ($siteSchema->schemaComponents as $schema)
            <x-dynamic-component :component="$schema" />
        @endforeach

        @if ($siteSchema->customSchema !== null)
            <script type="application/ld+json">
                {!! JsonLdScriptSanitizer::sanitize($siteSchema->customSchema) !!}
            </script>
        @endif
    </x-capell::app.body>
    {!! (new HeadMetadataCollector)->collect((string) ob_get_clean()) !!}
</html>
