<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Frontend\Actions\BuildErrorPageCopyDiagnosticsAction;
use Capell\Frontend\Actions\BuildStaticErrorPageDiagnosticsAction;
use Capell\Frontend\Data\ErrorPageCopyDiagnosticsData;
use Capell\Frontend\Data\ErrorPageCopySourceData;
use Capell\Frontend\Data\StaticErrorPageCandidateData;
use Capell\Frontend\Data\StaticErrorPageDiagnosticsData;
use Capell\Frontend\Enums\StaticErrorPageResolutionReason;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Answer "why did this URL not serve the error page I expected?" without
 * patching bootstrap or throwing probe exceptions to print state.
 *
 * Strictly read-only: it resolves manifests, paths and translations, and
 * renders nothing. Safe to run in production.
 */
final class DiagnoseErrorPagesCommand extends Command
{
    protected $signature = 'capell:error-pages:diagnose
        {url? : Absolute URL or path to inspect}
        {--status=404 : HTTP status to diagnose}
        {--json : Output the report as JSON}';

    public function __construct()
    {
        parent::__construct();

        $this->description = (string) __('capell-frontend::diagnostics.error_pages.description');
    }

    public function handle(): int
    {
        $request = Request::create($this->url(), Request::METHOD_GET);
        $status = (string) $this->option('status');

        $static = BuildStaticErrorPageDiagnosticsAction::run(
            $request->getScheme(),
            $request->getHost(),
            $request->getPathInfo(),
            $status,
        );

        $copy = BuildErrorPageCopyDiagnosticsAction::run($request->getHost(), $status);

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'static' => $static->toArray(),
                'copy' => $copy->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $this->renderStatic($static, $request->getUri());
        $this->renderCopy($copy);

        return Command::SUCCESS;
    }

    private function renderStatic(StaticErrorPageDiagnosticsData $static, string $uri): void
    {
        $this->table(
            [$this->label('field'), $this->label('value')],
            [
                [$this->label('request_url'), $uri],
                [$this->label('scheme'), $static->scheme],
                [$this->label('host'), $static->host],
                [$this->label('path'), $static->path],
                [$this->label('status'), $static->status],
                [$this->label('store_bound'), $this->bool($static->storeBound)],
                [$this->label('manifest_path'), $static->manifestPath],
                [$this->label('manifest_exists'), $this->bool($static->manifestExists)],
                [$this->label('entries_considered'), (string) count($static->candidates)],
                [$this->label('resolved'), $this->bool($static->resolved)],
                [$this->label('reason'), $static->reason?->value ?? $this->label('none')],
                [$this->label('resolved_file'), $static->resolvedFilePath ?? $this->label('none')],
                [$this->label('resolved_file_exists'), $static->resolvedFileExists === null
                    ? $this->label('unknown')
                    : $this->bool($static->resolvedFileExists)],
            ],
        );

        $this->newLine();
        $this->line($this->label('candidates_heading'));

        if ($static->candidates === []) {
            $this->line($this->label('no_candidates'));

            return;
        }

        $this->table(
            [
                $this->label('candidate_index'),
                $this->label('candidate_entry'),
                $this->label('candidate_outcome'),
                $this->label('candidate_expected'),
                $this->label('candidate_actual'),
            ],
            array_map(
                fn (StaticErrorPageCandidateData $candidate): array => [
                    (string) $candidate->index,
                    sprintf('%s / %s / %s / %s', $candidate->scheme, $candidate->domain, $candidate->status, $candidate->path),
                    $this->candidateOutcome($candidate),
                    $candidate->expected ?? $this->label('none'),
                    $candidate->actual ?? $this->label('none'),
                ],
                $static->candidates,
            ),
        );
    }

    private function candidateOutcome(StaticErrorPageCandidateData $candidate): string
    {
        if ($candidate->rejectedBy instanceof StaticErrorPageResolutionReason) {
            return $candidate->rejectedBy->value;
        }

        return $candidate->selected
            ? $this->label('candidate_selected')
            : $this->label('candidate_matched');
    }

    private function renderCopy(ErrorPageCopyDiagnosticsData $copy): void
    {
        $this->newLine();
        $this->line((string) __('capell-frontend::diagnostics.error_pages.copy_heading', [
            'status' => $copy->status,
            'host' => $copy->host,
        ]));

        $this->table(
            [$this->label('field'), $this->label('value')],
            [
                [$this->label('fallback_manifest_path'), $copy->fallbackManifestPath],
                [$this->label('fallback_manifest_exists'), $this->bool($copy->fallbackManifestExists)],
                [$this->label('fallback_manifest_written_at'), $copy->fallbackManifestWrittenAt ?? $this->label('never')],
                [$this->label('view_path'), $copy->viewPath ?? $this->label('none')],
            ],
        );

        foreach ($copy->fields as $field => $sources) {
            $this->newLine();
            $this->line((string) __('capell-frontend::diagnostics.error_pages.copy_field_heading', ['field' => $field]));

            $this->table(
                [
                    $this->label('copy_order'),
                    $this->label('copy_source'),
                    $this->label('copy_present'),
                    $this->label('copy_value'),
                    $this->label('copy_outcome'),
                ],
                array_map(
                    fn (ErrorPageCopySourceData $source): array => [
                        (string) $source->order,
                        $source->source,
                        $this->bool($source->present),
                        $source->value ?? $this->label('none'),
                        $this->copyOutcome($source),
                    ],
                    $sources,
                ),
            );

            $this->line(sprintf(
                '%s: %s',
                (string) __('capell-frontend::diagnostics.error_pages.copy_winner', ['field' => $field]),
                $copy->winners[$field] ?? $this->label('none'),
            ));
        }

        $this->newLine();
        $this->warn($this->label('regeneration_warning'));
    }

    private function copyOutcome(ErrorPageCopySourceData $source): string
    {
        if ($source->won) {
            return $this->label('copy_won');
        }

        if (! $source->consulted) {
            return $this->label('copy_not_consulted');
        }

        return $source->skippedBecause ?? $this->label('none');
    }

    private function url(): string
    {
        $url = $this->argument('url');
        $url = is_string($url) && $url !== '' ? $url : '/';

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');

        return $baseUrl . '/' . ltrim($url, '/');
    }

    private function bool(bool $value): string
    {
        return $value ? $this->label('yes') : $this->label('no');
    }

    private function label(string $key): string
    {
        return (string) __('capell-frontend::diagnostics.error_pages.' . $key);
    }
}
