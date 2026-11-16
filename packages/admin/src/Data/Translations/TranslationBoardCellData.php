<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Translations;

use Capell\Admin\Enums\TranslationBoardStatus;
use Spatie\LaravelData\Data;

final class TranslationBoardCellData extends Data
{
    public function __construct(
        public readonly int $languageId,
        public readonly TranslationBoardStatus $status,
    ) {}
}
