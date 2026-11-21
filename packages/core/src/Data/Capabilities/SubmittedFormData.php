<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One accepted form submission, as values only: no models, so queued listeners
 * and other packages never load the form package's classes. `source` is set only
 * when the submission was stored; listeners that need the stored record act on
 * stored submissions alone.
 */
final class SubmittedFormData extends Data
{
    /**
     * @param  array<string, mixed>  $values  submitted field key => value
     */
    public function __construct(
        public FormReferenceData $form,
        public ?int $submissionId,
        public array $values,
        public CarbonImmutable $submittedAt,
        public ?string $locale = null,
        public ?string $sourceUrl = null,
        public ?FormSubmissionSourceData $source = null,
        public ?string $referer = null,
        public ?string $userAgent = null,
    ) {}
}
