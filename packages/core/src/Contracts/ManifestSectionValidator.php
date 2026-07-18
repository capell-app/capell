<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

/** @internal */
interface ManifestSectionValidator
{
    /** @param array<string, mixed> $data */
    public function validate(array $data): void;
}
