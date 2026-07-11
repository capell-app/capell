@php
    use Filament\Support\Facades\FilamentAsset;

    /** @var array{heartbeatUrl: string, releaseUrl: string, csrfToken: string, intervalMs: int} $config */
@endphp

<div
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('capell-content-lock-heartbeat', 'capell-admin') }}"
    x-data="capellContentLockHeartbeat(@js($config))"
    x-init="init()"
    data-capell-content-lock-heartbeat
    style="display: none"
    aria-hidden="true"
></div>
