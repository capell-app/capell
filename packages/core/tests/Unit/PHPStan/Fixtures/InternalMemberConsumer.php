<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\PHPStan\Fixtures;

final class InternalMemberConsumer
{
    public function __construct(private readonly InternalMemberProvider $provider) {}

    /** @return list<string> */
    public function internalUse(): array
    {
        return [
            $this->provider->hiddenMethod(),
            $this->provider->hiddenProperty,
            InternalMemberProvider::hiddenStaticMethod(),
            InternalMemberProvider::$hiddenStatic,
            InternalMemberProvider::HIDDEN_CONSTANT,
        ];
    }

    /** @return list<string> */
    public function stableUse(): array
    {
        return [
            $this->provider->openMethod(),
            $this->provider->openProperty,
            InternalMemberProvider::OPEN_CONSTANT,
        ];
    }
}
