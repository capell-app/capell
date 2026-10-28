<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.work_first_content_status')"
    >
        <dl class="grid grid-cols-2 gap-3 text-sm">
            @foreach ($this->counts as $status => $count)
                <div>
                    <dt>
                        {{ __('capell-admin::dashboard.work_first_status_' . $status) }}
                    </dt>
                    <dd class="font-semibold">{{ number_format($count) }}</dd>
                </div>
            @endforeach
        </dl>
        @if (\Capell\Admin\Filament\Resources\Pages\PageResource::canViewAny())
            <a
                class="mt-3 inline-block text-sm hover:underline"
                href="{{ \Capell\Admin\Filament\Resources\Pages\PageResource::getUrl() }}"
                >{{ __('capell-admin::dashboard.work_first_view_all_pages') }}</a
            >
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
