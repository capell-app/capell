<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class PageRestoreResultData extends Data
{
    public function __construct(
        public readonly bool $restored,
        public readonly ?string $notice = null,
    ) {}
}
