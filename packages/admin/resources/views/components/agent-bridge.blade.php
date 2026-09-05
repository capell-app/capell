@php
    use Filament\Support\Facades\FilamentAsset;

    $configuration = [
        'definitionsUrl' => route('capell-admin.agent.tools'),
        'invokeUrl' => route('capell-admin.agent.tools.invoke'),
        'csrf' => csrf_token(),
        'title' => __('capell-admin::agent.bridge_title'),
        'approve' => __('capell-admin::agent.bridge_approve'),
        'cancel' => __('capell-admin::agent.bridge_cancel'),
        'error' => __('capell-admin::agent.bridge_error'),
        'busy' => __('capell-admin::agent.bridge_busy'),
    ];
@endphp

<div
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('capell-agent-admin', 'capell-admin') }}"
    x-data="agentAdminBridge(@js($configuration))"
    hidden
></div>
