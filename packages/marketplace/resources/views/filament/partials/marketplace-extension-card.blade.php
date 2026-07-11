@include('capell-admin::filament.pages.extensions.extension-card', [
    'record' => is_array($record ?? null) ? $record : $getRecord(),
    'livewire' => $livewire ?? (isset($getLivewire) ? $getLivewire() : null),
    'extraAttributes' => $extraAttributes ?? (isset($getExtraAttributes) ? $getExtraAttributes() : []),
])
