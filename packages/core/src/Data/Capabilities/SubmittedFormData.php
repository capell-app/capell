<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One accepted form submission, as values only: no models, so queued listeners
 * and other packages never load the form package's classes.
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
    ) {}
}
