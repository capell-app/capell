@props([
    'triggers' => [],
    'class' => null,
])

@php
    use Capell\Frontend\Actions\BuildInteractionRenderDataAction;

    $renderTriggers = BuildInteractionRenderDataAction::run($triggers);
@endphp

@if ($renderTriggers !== [])
    <div
        {{ $attributes->class(['capell-interactions flex flex-wrap items-center gap-3', $class]) }}
    >
        @foreach ($renderTriggers as $trigger)
            @if (in_array($trigger['target_type'], ['url', 'public_action'], true))
                <a
                    href="{{ $trigger['target_url'] }}"
                    class="capell-interaction capell-interaction--{{ $trigger['style'] }}"
                    @if ($trigger['icon'])
                        data-capell-interaction-icon="{{ $trigger['icon'] }}"
                    @endif
                    @if ($trigger['analytics_key'])
                        data-capell-interaction-analytics="{{ $trigger['analytics_key'] }}"
                    @endif
                    @if ($trigger['fallback_url'])
                        data-capell-interaction-fallback-url="{{ $trigger['fallback_url'] }}"
                    @endif
                    aria-label="{{ $trigger['aria_label'] }}"
                    title="{{ $trigger['aria_label'] }}"
                >
                    {{ $trigger['label'] }}
                </a>
            @else
                <a
                    href="{{ $trigger['target_url'] }}"
                    class="capell-interaction capell-interaction--{{ $trigger['style'] }}"
                    data-capell-interaction
                    data-capell-interaction-event="{{ $trigger['event'] }}"
                    data-capell-interaction-behavior="{{ $trigger['behavior'] }}"
                    data-capell-interaction-target-url="{{ $trigger['target_url'] }}"
                    data-capell-interaction-target-type="{{ $trigger['target_type'] }}"
                    @if ($trigger['icon'])
                        data-capell-interaction-icon="{{ $trigger['icon'] }}"
                    @endif
                    @if ($trigger['modal_size'])
                        data-capell-interaction-modal-size="{{ $trigger['modal_size'] }}"
                    @endif
                    @if ($trigger['analytics_key'])
                        data-capell-interaction-analytics="{{ $trigger['analytics_key'] }}"
                    @endif
                    @if ($trigger['fallback_url'])
                        data-capell-interaction-fallback-url="{{ $trigger['fallback_url'] }}"
                    @endif
                    data-capell-interaction-close-on-backdrop="{{ $trigger['close_on_backdrop'] ? 'true' : 'false' }}"
                    data-capell-interaction-close-label="{{ __('capell-frontend::generic.close') }}"
                    data-capell-interaction-loading-label="{{ __('capell-frontend::generic.interaction_loading') }}"
                    data-capell-interaction-ready-label="{{ __('capell-frontend::generic.interaction_ready') }}"
                    data-capell-interaction-error-label="{{ __('capell-frontend::generic.interaction_error') }}"
                    data-capell-interaction-asset-error-label="{{ __('capell-frontend::generic.interaction_asset_error') }}"
                    data-capell-interaction-retry-label="{{ __('capell-frontend::generic.retry') }}"
                    data-capell-interaction-fallback-label="{{ __('capell-frontend::generic.interaction_fallback') }}"
                    aria-label="{{ $trigger['aria_label'] }}"
                    title="{{ $trigger['aria_label'] }}"
                >
                    {{ $trigger['label'] }}
                </a>
            @endif
        @endforeach

        <span
            class="capell-interaction-visually-hidden"
            role="status"
            aria-live="polite"
            data-capell-interaction-status
        ></span>
    </div>
@endif
