<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use InvalidArgumentException;

final readonly class TransportResultData
{
    private function __construct(
        public string $receipt,
        public bool $accepted,
        public ?string $reason = null,
    ) {}

    public static function delivered(): self
    {
        return new self('delivered', true);
    }

    public static function receipt(string $receipt): self
    {
        throw_unless(in_array($receipt, ['delivered', 'disabled', 'rate_limited', 'unavailable'], true), InvalidArgumentException::class, 'Reporting transport returned an invalid receipt.');

        return $receipt === 'delivered'
            ? self::delivered()
            : new self($receipt, false, 'transport_unavailable');
    }

    public static function unavailable(): self
    {
        return new self('unavailable', false, 'transport_unavailable');
    }
}
