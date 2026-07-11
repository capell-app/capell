<x-filament-widgets::widget>
    <div class="space-y-4">
        @foreach ($this->content() as $content)
            {{ $content }}
        @endforeach
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
