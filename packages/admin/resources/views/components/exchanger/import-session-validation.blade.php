@php
    use Capell\Backup\Models\ImportSession;

    /** @var ImportSession $record */
    $record = $getRecord();
    $validationResults = is_array($record->validation_results) ? $record->validation_results : [];

    $pagesBuckets = is_array($validationResults['pages'] ?? null)
        ? $validationResults['pages']
        : ['create' => 0, 'update' => 0, 'skip' => 0];
    $relationsBuckets = is_array($validationResults['relations'] ?? null)
        ? $validationResults['relations']
        : ['match' => 0, 'create' => 0, 'clone' => 0, 'update' => 0, 'skip' => 0];
    $mediaBuckets = is_array($validationResults['media'] ?? null)
        ? $validationResults['media']
        : ['import' => 0, 'reuse' => 0];
    $blockingErrors = is_array($validationResults['blocking_errors'] ?? null) ? $validationResults['blocking_errors'] : [];
    $warningEntries = is_array($validationResults['warnings'] ?? null) ? $validationResults['warnings'] : [];
    $hasStructuredShape = isset($validationResults['pages']) || isset($validationResults['relations']) || isset($validationResults['media']);
@endphp

@if ($hasStructuredShape)
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold tracking-wider uppercase">
                {{ __('capell-admin::exchanger.summary_pages') }}
            </h3>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_create') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $pagesBuckets['create'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_update') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $pagesBuckets['update'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_skip') }}
                    </dt>
                    <dd class="font-mono">{{ $pagesBuckets['skip'] ?? 0 }}</dd>
                </div>
            </dl>
        </div>
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold tracking-wider uppercase">
                {{ __('capell-admin::exchanger.summary_relations') }}
            </h3>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_match') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $relationsBuckets['match'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_create') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $relationsBuckets['create'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_clone') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $relationsBuckets['clone'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_update') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $relationsBuckets['update'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_skip') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $relationsBuckets['skip'] ?? 0 }}
                    </dd>
                </div>
            </dl>
        </div>
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold tracking-wider uppercase">
                {{ __('capell-admin::exchanger.summary_media') }}
            </h3>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_import') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $mediaBuckets['import'] ?? 0 }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>
                        {{ __('capell-admin::exchanger.summary_reuse') }}
                    </dt>
                    <dd class="font-mono">
                        {{ $mediaBuckets['reuse'] ?? 0 }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    @if (! empty($blockingErrors))
        <div
            class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950"
        >
            <h3 class="text-sm font-semibold text-rose-800 dark:text-rose-200">
                {{ __('capell-admin::exchanger.summary_blocking_errors') }}
            </h3>
            <ul
                class="mt-2 list-disc space-y-1 pl-6 text-sm text-rose-700 dark:text-rose-200"
            >
                @foreach ($blockingErrors as $blockingMessage)
                    <li>{{ $blockingMessage }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($warningEntries))
        <div
            class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950"
        >
            <h3
                class="text-sm font-semibold text-amber-800 dark:text-amber-200"
            >
                {{ __('capell-admin::exchanger.summary_warnings') }}
            </h3>
            <ul
                class="mt-2 list-disc space-y-1 pl-6 text-sm text-amber-700 dark:text-amber-200"
            >
                @foreach ($warningEntries as $warningMessage)
                    <li>{{ $warningMessage }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@else
    <pre
        class="overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900"
    ><code>{{ json_encode($validationResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
@endif
