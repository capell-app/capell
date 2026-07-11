<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

final class BackendResolver
{
    public function name(): string
    {
        $configured = config('capell.media.backend');

        return is_string($configured) && $configured !== '' ? $configured : 'spatie';
    }

    public function isSpatie(): bool
    {
        return $this->name() === 'spatie';
    }

    public function isCurator(): bool
    {
        return $this->name() === 'curator';
    }
}
