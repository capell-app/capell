@include('capell-admin::filament.pages.extensions.extension-card', [
    'record' => $getRecord(),
    'livewire' => isset($getLivewire) ? $getLivewire() : null,
    'extraAttributes' => $getExtraAttributes(),
])
