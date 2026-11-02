<x-filament-panels::page>
    @unless ($canManage)
        <p role="note">{{ __('capell-admin::security.read_only') }}</p>
    @endunless
    <x-filament::section :heading="__('capell-admin::security.summary')">
        <div class="flex flex-wrap gap-4">
            @foreach (['critical', 'high', 'medium', 'low'] as $severity)
                <span
                    >{{ __('capell-admin::security.severities.' . $severity) }}: {{ $counts[$severity] ?? 0 }}</span
                >
            @endforeach
        </div>
        <p>{{ __('capell-admin::security.last_scan') }}: {{ $lastSeen ?? __('capell-admin::security.never') }}</p>
        <p>{{ __('capell-admin::security.analyser') }}: {{ $analyserName }} — {{ $analyserAvailable ? __('capell-admin::security.analyser_available') : __('capell-admin::security.analyser_unavailable') }}</p>
    </x-filament::section>
    {{ $this->table }}
    <x-filament::section :heading="__('capell-admin::security.approvals')">
        @forelse ($approvals as $approval)
            <article class="border-b py-3">
                <h3 class="font-semibold">
                    {{ $approval['weakness'] }} — {{ $approval['active'] ? __('capell-admin::security.approved') : __('capell-admin::security.revoked') }}
                </h3>
                <p>{{ $approval['description'] }}</p>
                <p>{{ __('capell-admin::security.approved_by', ['user' => $approval['approver'] ?? '—', 'date' => $approval['approvedAt']]) }}</p>
                @if ($approval['note'])
                    <p>{{ $approval['note'] }}</p>
                @endif
                @if ($approval['revokedAt'])
                    <p>{{ __('capell-admin::security.revoked') }}: {{ $approval['revokedAt'] }}</p>
                @endif
                @if ($approval['active']) {{ ($this->revokeApprovalAction)(['weakness' => $approval['weakness']]) }} @endif
            </article>
        @empty
            <p>{{ __('capell-admin::security.no_approvals') }}</p>
        @endforelse
    </x-filament::section>
    <x-filament::section :heading="__('capell-admin::security.history')">
        @unless ($this->showAllHistory)
            <p>{{ __('capell-admin::security.history_limit') }}</p>
        @endunless
        <x-filament::button
            color="gray"
            wire:click="$toggle('showAllHistory')"
        >
            {{ $this->showAllHistory ? __('capell-admin::security.show_recent_history') : __('capell-admin::security.show_all_history') }}
        </x-filament::button>
        @forelse ($runs as $run)
            <article class="border-b py-3">
                <h3 class="font-semibold">
                    {{ $run['when'] }} — {{ $run['weakness'] }}
                </h3>
                <p>{{ $run['actor'] }} — {{ $run['outcome'] }} — {{ $run['message'] }}</p>
                <details>
                    <summary>
                        {{ __('capell-admin::security.details') }}
                    </summary>
                    <p>{{ __('capell-admin::security.before') }}</p>
                    <pre
                        class="whitespace-pre-wrap"
                        >{{ json_encode($run['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre
                    >
                    <p>{{ __('capell-admin::security.after') }}</p>
                    <pre
                        class="whitespace-pre-wrap"
                        >{{ json_encode($run['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre
                    >
                </details>
            </article>
        @empty
            <p>{{ __('capell-admin::security.no_history') }}</p>
        @endforelse
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
