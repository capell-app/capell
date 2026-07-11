<?php

declare(strict_types=1);

namespace Capell\Core\Models\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

interface Userstampable
{
    public function isUserstamping(): bool;

    public function getCreatedByColumn(): string;

    public function getUpdatedByColumn(): string;

    public function getDeletedByColumn(): string;

    public function createdAt(): ?CarbonImmutable;

    public function updatedAt(): ?CarbonImmutable;

    public function deletedAt(): ?CarbonImmutable;

    public function creatorUser(): ?Model;

    public function editorUser(): ?Model;

    public function destroyerUser(): ?Model;
}
