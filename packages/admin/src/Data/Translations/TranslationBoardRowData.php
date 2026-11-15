<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Translations;

use Spatie\LaravelData\Data;

final class TranslationBoardRowData extends Data
{
    /**
     * @param  list<TranslationBoardCellData>  $cells  One per board language, in the board's language order.
     */
    public function __construct(
        public readonly int $pageId,
        public readonly string $title,
        public readonly ?string $editUrl,
        public readonly array $cells,
    ) {}
}
