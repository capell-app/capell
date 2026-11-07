<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Symfony\Component\HttpFoundation\Response;

/** Package security middleware (such as MFA) that denies every request it guards. */
final class PackageSecurityDenyMiddleware
{
    public const string BODY = 'denied by package security';

    public function handle(): Response
    {
        return response(self::BODY, 403);
    }
}
