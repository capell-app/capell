<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Translations;

use Spatie\LaravelData\Data;

final class TranslationBoardLanguageData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isDefault,
    ) {}
}
