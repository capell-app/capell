<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

/**
 * A guarded, idempotent modification to an application file (models, providers,
 * config, .env, routes). Implementations probe the current state before
 * applying so callers can surface "already applied" or "customised" states
 * instead of blindly rewriting user code.
 */
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
