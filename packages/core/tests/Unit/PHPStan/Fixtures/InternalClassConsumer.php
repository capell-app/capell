<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\PHPStan\Fixtures;

use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use DateTimeImmutable;

final class InternalClassConsumer
{
    public const string INTERNAL = DoctorCheck::class;

    public const string EXPERIMENTAL = RenderHookRegistry::class;

    public const string STABLE = ExtensionContribution::class;

    public const string OTHER_VENDOR = DateTimeImmutable::class;
}
