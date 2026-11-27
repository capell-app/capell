<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;
use Spatie\LaravelSettings\Attributes\ShouldBeEncrypted;
use Spatie\LaravelSettings\Settings;

final class EncryptedProbeSettings extends Settings
{
    #[ShouldBeEncrypted]
    public string $api_token;

    #[Override]
    public static function group(): string
    {
        return 'encrypted-probe';
    }
}
