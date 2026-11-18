@props([
    'title' => '',
    'livewireEnabled' => true,
    'runtimeManifest' => null,
    'resourcePlan' => null,
])

@includeIsolated('capell::public.head', [
    'renderData' => app(\Capell\Frontend\Data\PublicHeadData::class, [
        'title' => $title,
        'livewireEnabled' => (bool) $livewireEnabled,
        'runtimeManifest' => $runtimeManifest,
        'resourcePlan' => $resourcePlan,
    ]),
])
