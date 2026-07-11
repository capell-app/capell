<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

class FrontendResourceValidationResultData extends Data
{
    /**
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public bool $valid = true,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public static function valid(): self
    {
        return new self;
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public static function invalid(array $errors, array $warnings = []): self
    {
        return new self(false, $warnings, $errors);
    }
}
