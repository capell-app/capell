<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\PHPStan\Fixtures;

final class InternalMemberProvider
{
    /** @internal */
    public const string HIDDEN_CONSTANT = 'hidden';

    public const string OPEN_CONSTANT = 'open';

    /** @internal */
    public static string $hiddenStatic = 'hidden';

    /** @internal */
    public string $hiddenProperty = 'hidden';

    public string $openProperty = 'open';

    /** @internal */
    public static function hiddenStaticMethod(): string
    {
        return 'hidden';
    }

    /** @internal */
    public function hiddenMethod(): string
    {
        return 'hidden';
    }

    public function openMethod(): string
    {
        return $this->hiddenMethod() . $this->hiddenProperty;
    }
}
