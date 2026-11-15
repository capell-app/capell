<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::host-monitor.title')"
        :description="__('capell-admin::host-monitor.description')"
        icon="heroicon-o-exclamation-triangle"
        :icon-color="$assessments[0]->status->value === 'critical' ? 'danger' : 'warning'"
    >
        <ul class="space-y-3">
            @foreach ($assessments as $assessment)
                <li>
                    <p class="font-semibold">
                        {{ __('capell-admin::host-monitor.node', ['node' => $assessment->sample?->node, 'status' => $assessment->status->label()]) }}
                    </p>
                    <ul class="list-disc ps-5">
                        @foreach ($assessment->reasons as $reason)
                            <li>{{ $reason['message'] }}</li>
                        @endforeach
                    </ul>
                    @if ($assessment->sample && $assessment->sample->topProcesses !== [])
                        <p class="mt-1 text-sm">
                            {{ __('capell-admin::host-monitor.busiest') }} {{ collect($assessment->sample->topProcesses)->take(3)->map(fn (array $process): string => ($process['command'] !== '' ? $process['command'] : 'pid ' . $process['pid']) . ' (' . number_format($process['cpu'], 0) . '%)')->implode(', ') }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
        <a
            href="{{ $siteHealthUrl }}"
            class="mt-3 inline-block font-semibold underline"
            >{{ __('capell-admin::host-monitor.open_site_health') }}</a
        >
    </x-filament::section>
</x-filament-widgets::widget>
