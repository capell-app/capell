<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-marketplace::marketplace.operations.widget_heading')"
        :description="__('capell-marketplace::marketplace.operations.widget_description')"
    >
        @if ($this->operations->isEmpty() && $this->flowSessions->isEmpty())
            <div
                class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300"
            >
                {{ __('capell-marketplace::marketplace.operations.empty') }}
            </div>
        @else
            <div class="space-y-4">
                @if ($this->flowSessions->isNotEmpty())
                    <div class="space-y-2">
                        <h3
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.operations.flow_sessions_heading') }}
                        </h3>

                        <div
                            class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10"
                        >
                            @foreach ($this->flowSessions as $session)
                                @php
                                    $quotedExtensions = collect(is_array($session->quoted_extensions) ? $session->quoted_extensions : []);
                                    $selectedExtensions = collect(is_array($session->selected_extensions) ? $session->selected_extensions : []);
                                    $composerNames = $quotedExtensions
                                        ->merge($selectedExtensions)
                                        ->pluck('composer_name')
                                        ->filter(fn ($composerName) => is_string($composerName) && $composerName !== '')
                                        ->unique()
                                        ->values();
                                    $entitlementIds = collect(is_array($session->remote_entitlement_ids) ? $session->remote_entitlement_ids : [])
                                        ->filter(fn ($entitlementId) => is_scalar($entitlementId) && $entitlementId !== '')
                                        ->values();
                                @endphp

                                <div
                                    class="space-y-3 bg-white p-4 dark:bg-gray-900"
                                >
                                    <div
                                        class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <div
                                                class="flex flex-wrap items-center gap-2"
                                            >
                                                <h4
                                                    class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                                                >
                                                    {{ $session->remote_flow_id ?? __('capell-marketplace::marketplace.operations.flow_session_pending') }}
                                                </h4>
                                                <span
                                                    class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-600/20 dark:bg-white/10 dark:text-gray-200"
                                                >
                                                    {{ $this->flowSessionStatusLabel($session) }}
                                                </span>
                                            </div>

                                            <p
                                                class="mt-1 text-xs break-all text-gray-500 dark:text-gray-400"
                                            >
                                                {{ $composerNames->isNotEmpty() ? $composerNames->implode(', ') : __('capell-marketplace::marketplace.operations.flow_session_unknown_packages') }}
                                            </p>
                                        </div>

                                        <div class="flex flex-wrap gap-2">
                                            @if ($this->canResumeFlowSession($session))
                                                <button
                                                    type="button"
                                                    wire:click="resumeFlowSession({{ $session->getKey() }})"
                                                    class="text-primary-700 ring-primary-600/20 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-500/10 inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 transition"
                                                >
                                                    {{ __('capell-marketplace::marketplace.operations.flow_resume') }}
                                                </button>
                                            @endif

                                            @if ($this->canExpireFlowSession($session))
                                                <button
                                                    type="button"
                                                    wire:click="expireFlowSession({{ $session->getKey() }})"
                                                    wire:confirm="{{ __('capell-marketplace::marketplace.operations.flow_expire_confirm') }}"
                                                    class="text-danger-700 ring-danger-600/20 hover:bg-danger-50 dark:text-danger-300 dark:hover:bg-danger-500/10 inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 transition"
                                                >
                                                    {{ __('capell-marketplace::marketplace.operations.flow_expire') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    <dl
                                        class="grid gap-2 text-xs text-gray-600 sm:grid-cols-2 lg:grid-cols-6 dark:text-gray-300"
                                    >
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_support_reference') }}
                                            </dt>
                                            <dd class="mt-1 break-all">
                                                {{ $this->flowSessionSupportReference($session) }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_account_email') }}
                                            </dt>
                                            <dd class="mt-1 break-all">
                                                {{ $this->flowSessionAccountEmail($session) }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_quote') }}
                                            </dt>
                                            <dd class="mt-1">
                                                {{ strtoupper($session->quoted_currency ?? '') }}
                                                {{ number_format($session->quoted_price_cents / 100, 2) }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_last_safe_action') }}
                                            </dt>
                                            <dd class="mt-1">
                                                {{ $this->flowSessionLastSafeAction($session) }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_entitlements') }}
                                            </dt>
                                            <dd class="mt-1 break-all">
                                                {{ $entitlementIds->isNotEmpty() ? $entitlementIds->implode(', ') : '-' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_returned_at') }}
                                            </dt>
                                            <dd class="mt-1">
                                                {{ $session->returned_at?->diffForHumans() ?? '-' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-medium text-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.flow_expires_at') }}
                                            </dt>
                                            <dd class="mt-1">
                                                {{ $session->expires_at?->diffForHumans() ?? '-' }}
                                            </dd>
                                        </div>
                                    </dl>

                                    @if ($session->last_error || $session->failure_reason)
                                        <p
                                            class="bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300 rounded-lg px-3 py-2 text-sm"
                                        >
                                            {{ $session->last_error ?? $session->failure_reason }}
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($this->operations->isNotEmpty())
                    <div
                        class="flex flex-wrap gap-2 rounded-lg bg-gray-50 p-1 dark:bg-white/5"
                    >
                        @foreach ([
                                      'active' => [
                                          'label' => __('capell-marketplace::marketplace.operations.tab_active'),
                                          'count' => $this->activeOperationsCount(),
                                      ],
                                      'failed' => [
                                          'label' => __('capell-marketplace::marketplace.operations.tab_failed'),
                                          'count' => $this->failedOperationsCount(),
                                      ],
                                      'all' => [
                                          'label' => __('capell-marketplace::marketplace.operations.tab_all'),
                                          'count' => $this->operations->count(),
                                      ],
                                  ] as $tab => $tabData)
                            <button
                                type="button"
                                wire:click="setOperationsTab('{{ $tab }}')"
                                @class([
                                    'inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold transition',
                                    'bg-white text-gray-950 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:text-white dark:ring-white/10' => $this->operationsTab === $tab,
                                    'text-gray-600 hover:bg-white/70 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white' => $this->operationsTab !== $tab,
                                ])
                            >
                                <span>{{ $tabData['label'] }}</span>
                                <span
                                    class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300"
                                >
                                    {{ $tabData['count'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>

                    @if ($this->visibleOperations->isEmpty())
                        <div
                            class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300"
                        >
                            {{ __('capell-marketplace::marketplace.operations.empty_tab') }}
                        </div>
                    @endif

                    <div
                        class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10"
                    >
                        @foreach ($this->visibleOperations as $operation)
                            @php
                                $deployment = is_array($operation->deployment) ? $operation->deployment : [];
                                $deploymentStatus = is_string($deployment['status'] ?? null) ? $deployment['status'] : null;
                            @endphp

                            <div
                                class="space-y-3 bg-white p-4 dark:bg-gray-900"
                            >
                                <div
                                    class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <h3
                                                class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                                            >
                                                {{ $operation->extension_name }}
                                            </h3>
                                            <span
                                                class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-600/20 dark:bg-white/10 dark:text-gray-200"
                                            >
                                                {{ str($operation->status->value)->replace('_', ' ')->headline() }}
                                            </span>
                                            @if ($deploymentStatus !== null)
                                                <span
                                                    class="bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300 rounded-md px-2 py-1 text-xs font-medium ring-1"
                                                >
                                                    {{ __('capell-marketplace::marketplace.operations.deployment_status', ['status' => str($deploymentStatus)->headline()]) }}
                                                </span>
                                            @endif
                                        </div>

                                        <p
                                            class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ $operation->composer_name }}
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @if ($this->hasOperationLogs($operation))
                                            <button
                                                type="button"
                                                wire:click="toggleOperationLog({{ $operation->getKey() }})"
                                                class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-600/20 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/10"
                                            >
                                                {{ $this->expandedOperationLogId === $operation->getKey() ? __('capell-marketplace::marketplace.operations.hide_logs') : __('capell-marketplace::marketplace.operations.view_logs') }}
                                            </button>
                                        @endif

                                        @if ($this->canCancel($operation))
                                            <button
                                                type="button"
                                                wire:click="cancel({{ $operation->getKey() }})"
                                                wire:confirm="{{ __('capell-marketplace::marketplace.operations.cancel_confirm') }}"
                                                class="text-danger-700 ring-danger-600/20 hover:bg-danger-50 dark:text-danger-300 dark:hover:bg-danger-500/10 inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 transition"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.cancel') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <dl
                                    class="grid gap-2 text-xs text-gray-600 sm:grid-cols-2 lg:grid-cols-4 dark:text-gray-300"
                                >
                                    <div>
                                        <dt
                                            class="font-medium text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-marketplace::marketplace.operations.command') }}
                                        </dt>
                                        <dd class="mt-1 break-all">
                                            {{ $operation->composer_command }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="font-medium text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-marketplace::marketplace.operations.queued_at') }}
                                        </dt>
                                        <dd class="mt-1">
                                            {{ $operation->queued_at?->diffForHumans() ?? '-' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="font-medium text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-marketplace::marketplace.operations.started_at') }}
                                        </dt>
                                        <dd class="mt-1">
                                            {{ $operation->started_at?->diffForHumans() ?? '-' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="font-medium text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-marketplace::marketplace.operations.deployment_reference') }}
                                        </dt>
                                        <dd class="mt-1 break-all">
                                            {{ $deployment['reference'] ?? $deployment['failure_reason'] ?? '-' }}
                                        </dd>
                                    </div>
                                </dl>

                                @if ($operation->failure_reason)
                                    <p
                                        class="bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300 rounded-lg px-3 py-2 text-sm"
                                    >
                                        {{ $operation->failure_reason }}
                                    </p>
                                @endif

                                @if ($this->expandedOperationLogId === $operation->getKey())
                                    <div class="space-y-3">
                                        @foreach ($this->operationLogEntries($operation) as $logEntry)
                                            @if ($logEntry['content'] !== '')
                                                <div
                                                    class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10"
                                                >
                                                    <div
                                                        class="bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-600 dark:bg-white/5 dark:text-gray-300"
                                                    >
                                                        {{ $logEntry['label'] }}
                                                    </div>
                                                    <pre
                                                        class="max-h-80 overflow-auto bg-gray-950 p-3 text-xs leading-5 whitespace-pre-wrap text-gray-100"
                                                    >
{{ $logEntry['content'] }}</pre
                                                    >
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
