@php
    use Capell\Backup\Models\ImportSession;

    /** @var ImportSession $record */
    $record = $getRecord();
    $resultSummary = is_array($record->result_summary) ? $record->result_summary : [];

    $pagesImported = (int) ($resultSummary['pages_imported'] ?? $resultSummary['pages_created'] ?? 0);
    $pagesSkipped = (int) ($resultSummary['pages_skipped'] ?? 0);
    $pageUrlsCreated = (int) ($resultSummary['page_urls_created'] ?? 0);
    $mediaIngested = (int) ($resultSummary['media_ingested'] ?? $resultSummary['media_reassigned'] ?? 0);
    $relationsResolved = (int) ($resultSummary['relations_resolved'] ?? 0);
    $errors = is_array($resultSummary['errors'] ?? null) ? $resultSummary['errors'] : [];
    $hasStructuredShape = array_key_exists('pages_created', $resultSummary)
        || array_key_exists('pages_imported', $resultSummary);
@endphp

@if ($hasStructuredShape)
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <dt class="text-xs text-gray-500 uppercase">
                {{ __('capell-admin::exchanger.result_pages_imported') }}
            </dt>
            <dd class="font-mono text-lg">{{ $pagesImported }}</dd>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <dt class="text-xs text-gray-500 uppercase">
                {{ __('capell-admin::exchanger.result_pages_skipped') }}
            </dt>
            <dd class="font-mono text-lg">{{ $pagesSkipped }}</dd>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <dt class="text-xs text-gray-500 uppercase">
                {{ __('capell-admin::exchanger.result_page_urls_created') }}
            </dt>
            <dd class="font-mono text-lg">{{ $pageUrlsCreated }}</dd>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <dt class="text-xs text-gray-500 uppercase">
                {{ __('capell-admin::exchanger.result_media_ingested') }}
            </dt>
            <dd class="font-mono text-lg">{{ $mediaIngested }}</dd>
        </div>
        @if ($relationsResolved > 0)
            <div
                class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
            >
                <dt class="text-xs text-gray-500 uppercase">
                    {{ __('capell-admin::exchanger.result_relations_resolved') }}
                </dt>
                <dd class="font-mono text-lg">{{ $relationsResolved }}</dd>
            </div>
        @endif
    </dl>

    @if (! empty($errors))
        <div
            class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950"
        >
            <h3 class="text-sm font-semibold text-rose-800 dark:text-rose-200">
                {{ __('capell-admin::exchanger.result_errors') }}
            </h3>
            <ul
                class="mt-2 list-disc space-y-1 pl-6 text-sm text-rose-700 dark:text-rose-200"
            >
                @foreach ($errors as $errorMessage)
                    <li>{{ $errorMessage }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@else
    <pre
        class="overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900"
    ><code>{{ json_encode($resultSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
@endif
