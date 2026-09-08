@php
    use Capell\Admin\Actions\Media\BuildMediaImpactPreviewAction;
    use Capell\Core\Models\Media;

    /** @var Media|null $record */
    $impact = $record instanceof Media ? BuildMediaImpactPreviewAction::run($record) : null;
@endphp

@include('capell-admin::filament.forms.content-impact-preview', [
    'impact' => $impact,
    'showMediaGuidance' => true,
    'unavailableMessage' => __('capell-admin::media.impact_preview_unavailable'),
    'emptyMessage' => __('capell-admin::media.impact_preview_none'),
])
