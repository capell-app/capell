@include('capell-admin::filament.forms.content-impact-preview', [
    'impact' => \Capell\Admin\Actions\EditorImpact\BuildRecordImpactPreviewAction::run($record),
    'unavailableMessage' => __('capell-admin::impact.unavailable'),
    'emptyMessage' => __('capell-admin::impact.no_dependencies'),
])
