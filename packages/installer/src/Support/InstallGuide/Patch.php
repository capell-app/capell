<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

interface Patch
{
    public function id(): string;

    public function group(): string;

    public function label(): string;

    public function description(): string;

    public function docUrl(): ?string;

    public function defaultEnabled(): bool;

    public function probe(): PatchStatus;

    public function reason(): ?string;

    public function apply(): void;
}
