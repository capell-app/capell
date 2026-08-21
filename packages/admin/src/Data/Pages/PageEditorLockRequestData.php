<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Enums\PageEditorLockOperation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class PageEditorLockRequestData extends Data
{
    public function __construct(
        public readonly Model $record,
        public readonly ?Authenticatable $user,
        public readonly PageEditorLockOperation $operation,
    ) {}
}
