<div class="grid gap-2">
    @foreach ($getState() as $item)
        <div>
            <div>{{ $item['key'] }}</div>
            <div>
                {{ __('filament-logger::filament-logger.resource.label.old') }}:
                {{ Str::limit(strip_tags($item['old']), 30) }}
            </div>
            <div>
                {{ __('filament-logger::filament-logger.resource.label.new') }}:
                {{ Str::limit(strip_tags($item['new']), 30) }}
            </div>
        </div>
    @endforeach
</div>
