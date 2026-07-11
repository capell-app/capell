@php
    use Capell\Core\Actions\GetEditPageResourceUrlAction;
    use Filament\Pages\Page;

    $record = $getRecord();
    $livewire = $getLivewire();
    $recordUrl = '';
    if ($livewire instanceof Page) {
        $recordUrl = GetEditPageResourceUrlAction::run($record);
    }
@endphp

<div
    @if ($recordUrl) x-on:click.stop="window.location = '{{ $recordUrl }}'" @endif
    {{
        $attributes->merge($getExtraAttributes())->class([
            'filament-table-column-page-ancestors relative z-0 flex min-h-full w-full flex-col justify-center px-4 py-3',
            'cursor-pointer' => $recordUrl,
        ])
    }}
>
    @if ($record->ancestors && $record->ancestors->isNotEmpty())
        <div
            class="overflow-hidden text-sm leading-tight tracking-tight whitespace-nowrap text-gray-500 dark:text-gray-200"
        >
            @foreach ($record->ancestors as $ancestor)
                @if (! $loop->first)
                    &raquo;
                @endif

                <a
                    class="hover:text-primary-500 focus:text-primary-500 pointer-events-auto text-sm leading-tight text-gray-500 transition focus:underline dark:text-gray-200"
                    href="{{ GetEditPageResourceUrlAction::run($ancestor) }}"
                    title="{!! htmlspecialchars($ancestor->name) !!}"
                >
                    {{ Str::limit($ancestor->name, 20) }}
                </a>
            @endforeach
        </div>
    @endif
</div>
