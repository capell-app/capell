<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Concerns\Fixtures;

use Capell\Admin\Filament\Concerns\Validate\BlueprintValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;

final class BlueprintDeleteValidationHarness implements ValidatesDelete
{
    use BlueprintValidation;

    /** @var array<string, string> */
    public array $errors = [];

    public function addError(string $name, string $message): void
    {
        $this->errors[$name] = $message;
    }
}
