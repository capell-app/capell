<?php

declare(strict_types=1);

namespace Capell\Core\Events\Capabilities;

use Capell\Core\Data\Capabilities\SubmittedFormData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A form package accepted a submission. Listen to this instead of the form package's own event.
 */
final readonly class FormSubmissionReceived
{
    use Dispatchable;

    public function __construct(
        public SubmittedFormData $submission,
    ) {}
}
