<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

final class PageEditorScratchDraftInputData extends Data
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly Page $page,
        public readonly ?Authenticatable $user,
        public readonly string $locale,
        public readonly array $payload,
    ) {}
}
