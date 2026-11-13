<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureFailureData;
use Capell\Core\Data\Privacy\PrivacyErasureResultData;
use Capell\Core\Data\Privacy\PrivacyExportSectionData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Throwable;

/**
 * Every package's privacy exporters and erasers, in one Core-owned place.
 *
 * Packages register by container tag (EXPORTER_TAG, ERASER_TAG) or through
 * registerExporter()/registerEraser(), with no reference to the package that
 * handles privacy requests. That handler (privacy-center) reads them from here
 * and keeps the request workflow, review and retention; console commands and queued jobs can call export() and erase()
 * directly.
 *
 * Contributor keys are unique per kind, so two packages cannot write into the
 * same section of an export or receipt.
 */
final class PrivacySubjectRegistry
{
    /** Container tag for PrivacySubjectExporter implementations. */
    public const string EXPORTER_TAG = 'capell.privacy.subject-exporter';

    /** Container tag for PrivacySubjectEraser implementations. */
    public const string ERASER_TAG = 'capell.privacy.subject-eraser';

    private const string CONTRIBUTOR_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    /** @var array<string, PrivacySubjectExporter> */
    private array $exporters = [];

    /** @var array<string, PrivacySubjectEraser> */
    private array $erasers = [];

    private int $taggedExporterCount = 0;

    private int $taggedEraserCount = 0;

    public function __construct(private readonly Container $container) {}

    public function registerExporter(PrivacySubjectExporter $exporter): self
    {
        $this->exporters = $this->with($this->exporters, $exporter->contributor(), $exporter, 'exporter');

        return $this;
    }

    public function registerEraser(PrivacySubjectEraser $eraser): self
    {
        $this->erasers = $this->with($this->erasers, $eraser->contributor(), $eraser, 'eraser');

        return $this;
    }

    /** @return array<string, PrivacySubjectExporter> keyed by contributor */
    public function exporters(): array
    {
        $this->discoverTagged();

        return $this->exporters;
    }

    /** @return array<string, PrivacySubjectEraser> keyed by contributor */
    public function erasers(): array
    {
        $this->discoverTagged();

        return $this->erasers;
    }

    /**
     * Each supporting package's export section, keyed by contributor; packages
     * that hold nothing are left out.
     *
     * @return array<string, PrivacyExportSectionData>
     */
    public function export(PrivacySubject $subject): array
    {
        $sections = [];

        foreach ($this->exporters() as $contributor => $exporter) {
            if (! $exporter->supports($subject)) {
                continue;
            }

            $section = $exporter->export($subject);

            if (! $section->isEmpty()) {
                $sections[$contributor] = $section;
            }
        }

        ksort($sections);

        return $sections;
    }

    /**
     * Runs every supporting eraser for the subject, in contributor order. A
     * package that throws, or returns a receipt Core cannot accept, becomes a
     * failure in the result and the remaining packages still run, so no
     * finished erasure goes unrecorded. Any failure means the erasure is
     * incomplete and must be run again (erasers are safe to repeat): pass the
     * failed contributors as $only to run those alone. A named contributor
     * with no registered eraser is a failure, never a silent success. A thrown
     * exception goes to the application's exception handler; the result keeps
     * only its class.
     *
     * @param  list<string>|null  $only  contributor keys to run; null runs every eraser
     */
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode, ?array $only = null): PrivacyErasureResultData
    {
        $erasers = $this->erasers();
        $receipts = [];
        $failures = [];

        if ($only !== null) {
            $only = array_values(array_unique($only));
            sort($only);

            foreach (array_diff($only, array_keys($erasers)) as $missing) {
                $failures[] = new PrivacyErasureFailureData($missing, PrivacyErasureFailureData::CODE_CONTRIBUTOR_MISSING);
            }

            $erasers = array_intersect_key($erasers, array_flip($only));
        }

        ksort($erasers);

        foreach ($erasers as $contributor => $eraser) {
            try {
                if (! $eraser->supports($subject)) {
                    continue;
                }

                $receipt = $eraser->erase($subject, $mode);
            } catch (Throwable $throwable) {
                report($throwable);
                $failures[] = new PrivacyErasureFailureData($contributor, PrivacyErasureFailureData::CODE_ERASER_FAILED, $throwable::class);

                continue;
            }

            if ($receipt->contributor !== $contributor) {
                $failures[] = new PrivacyErasureFailureData($contributor, PrivacyErasureFailureData::CODE_RECEIPT_CONTRIBUTOR, rejectedReceipt: $receipt);

                continue;
            }

            if ($receipt->suppressionMarkerRetained && ! $eraser->retainsSuppressionMarker()) {
                $failures[] = new PrivacyErasureFailureData($contributor, PrivacyErasureFailureData::CODE_UNDECLARED_MARKER, rejectedReceipt: $receipt);

                continue;
            }

            $receipts[] = $receipt;
        }

        return new PrivacyErasureResultData($receipts, $failures);
    }

    /**
     * The contributors that would keep a suppression marker for the subject, so a
     * request can say so before erasure runs.
     *
     * @return list<string>
     */
    public function suppressionMarkerContributors(PrivacySubject $subject): array
    {
        $contributors = array_keys(array_filter(
            $this->erasers(),
            static fn (PrivacySubjectEraser $eraser): bool => $eraser->supports($subject) && $eraser->retainsSuppressionMarker(),
        ));
        sort($contributors);

        return $contributors;
    }

    /**
     * @template T of object
     *
     * @param  array<string, T>  $registered
     * @param  T  $contributor
     * @return array<string, T>
     */
    private function with(array $registered, string $key, object $contributor, string $kind): array
    {
        throw_unless(preg_match(self::CONTRIBUTOR_PATTERN, $key) === 1, LogicException::class, sprintf('The privacy %s key [%s] must be lower case letters, digits, dots, dashes or underscores.', $kind, $key));

        if (isset($registered[$key])) {
            throw_unless($contributor::class === $registered[$key]::class, LogicException::class, sprintf('Two privacy %ss use the key [%s].', $kind, $key));

            return $registered;
        }

        $registered[$key] = $contributor;

        return $registered;
    }

    /**
     * Resolves tagged contributors in container tag order, once each. Order does
     * not matter: exports and erasures are keyed and run by contributor key, and
     * a second package claiming a key is refused whichever registers first.
     */
    private function discoverTagged(): void
    {
        $exporters = iterator_to_array($this->container->tagged(self::EXPORTER_TAG), false);

        foreach (array_slice($exporters, $this->taggedExporterCount) as $exporter) {
            throw_unless($exporter instanceof PrivacySubjectExporter, LogicException::class, 'A tagged privacy exporter must implement ' . PrivacySubjectExporter::class . '.');
            $this->registerExporter($exporter);
        }

        $this->taggedExporterCount = count($exporters);

        $erasers = iterator_to_array($this->container->tagged(self::ERASER_TAG), false);

        foreach (array_slice($erasers, $this->taggedEraserCount) as $eraser) {
            throw_unless($eraser instanceof PrivacySubjectEraser, LogicException::class, 'A tagged privacy eraser must implement ' . PrivacySubjectEraser::class . '.');
            $this->registerEraser($eraser);
        }

        $this->taggedEraserCount = count($erasers);
    }
}
