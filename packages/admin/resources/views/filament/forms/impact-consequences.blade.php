@foreach ($consequences as $consequence)
    <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <p class="font-medium text-gray-950 dark:text-white">{{ $consequence->label }}: {{ $consequence->count }}</p>
        <p>{{ $consequence->description }}</p>
        @if ($consequence->urls !== [])
            <ul class="list-inside list-disc break-all">
                @foreach ($consequence->urls as $url)
                    <li>{{ $url }}</li>
                @endforeach
            </ul>
        @endif
        @if ($consequence->estimatedSeconds !== null)
            <p>{{ __('capell-admin::impact.estimated_seconds', ['seconds' => number_format($consequence->estimatedSeconds, 2)]) }}</p>
        @endif
        @if ($consequence->estimateBasis !== null)
            <p class="text-xs">{{ $consequence->estimateBasis }}</p>
        @endif
    </div>
@endforeach
