@if ($graph)
    <script
        type="application/ld+json"
        data-capell-agent-schema
    >{!! json_encode($graph, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
@if ($manifest)
    <script
        type="application/json"
        data-capell-agent-tools
    >{!! json_encode($manifest, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
