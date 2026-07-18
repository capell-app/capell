<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

final class InstallMemoryLimit
{
    public const int MINIMUM_BYTES = 536_870_912;

    public const string MINIMUM_DISPLAY = '512M';

    public function __construct(
        private readonly ?string $configuredLimit = null,
    ) {}

    public function current(): string
    {
        if ($this->configuredLimit !== null) {
            return $this->configuredLimit;
        }

        $configuredLimit = ini_get('memory_limit');

        return is_string($configuredLimit) ? $configuredLimit : '-1';
    }

    public function bytes(string $configuredLimit): int
    {
        return ini_parse_quantity(trim($configuredLimit));
    }

    public function isSatisfied(?string $configuredLimit = null): bool
    {
        $bytes = $this->bytes($configuredLimit ?? $this->current());

        return $bytes === -1 || $bytes >= self::MINIMUM_BYTES;
    }

    public function ensureMinimum(): void
    {
        if ($this->isSatisfied()) {
            return;
        }

        ini_set('memory_limit', self::MINIMUM_DISPLAY);
    }

    public function failureMessage(?string $configuredLimit = null): string
    {
        return sprintf(
            'Capell installation requires PHP memory_limit of at least %s; the current limit is %s.',
            self::MINIMUM_DISPLAY,
            $configuredLimit ?? $this->current(),
        );
    }
}
