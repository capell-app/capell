<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Enums\PageEditorLockStatus;
use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

final class PageEditorLockResultData extends Data
{
    public function __construct(
        public readonly PageEditorLockStatus $status,
        public readonly ?ContentLock $lock = null,
    ) {}

    public function isBlocked(): bool
    {
        return $this->status === PageEditorLockStatus::Conflict;
    }

    public function owner(): ?Authenticatable
    {
        $user = $this->lock?->user;

        return $user instanceof Authenticatable ? $user : null;
    }
}
