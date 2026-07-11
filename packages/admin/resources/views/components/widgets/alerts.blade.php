<x-filament-widgets::widget>
    @if ($this->alerts->isNotEmpty())
        <div class="space-y-4">
            @foreach ($this->alerts as $key => $alert)
                <x-filament::callout
                    :x-ref="'page-alert-' . $key"
                    :color="$alert->type->value"
                    :icon="$alert->icon"
                    :heading="$alert->title"
                    :description="$alert->message"
                >
                    @if ($alert->action)
                        <x-slot name="controls">
                            @foreach (Arr::wrap($alert->action) as $action)
                                {{ $action }}
                            @endforeach
                        </x-slot>
                    @endif
                </x-filament::callout>
            @endforeach
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-widgets::widget>
