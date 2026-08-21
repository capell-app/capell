<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Spatie\LaravelData\Data;

final class PageEditorScratchDraftResultData extends Data
{
    public function __construct(
        public readonly PageEditorScratchDraftStatus $status,
        public readonly int $affectedRows = 0,
    ) {}

    public function wasSaved(): bool
    {
        return $this->status === PageEditorScratchDraftStatus::Saved;
    }

    public function hasUser(): bool
    {
        return $this->status !== PageEditorScratchDraftStatus::Unauthenticated;
    }
}
