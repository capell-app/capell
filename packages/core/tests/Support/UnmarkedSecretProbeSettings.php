<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;
use Spatie\LaravelSettings\Settings;

/** A settings class whose secret carries no encryption metadata, like a package that forgot it. */
final class UnmarkedSecretProbeSettings extends Settings
{
    public string $stripe_secret_key;

    public string $display_name;

    #[Override]
    public static function group(): string
    {
        return 'unmarked-probe';
    }
}
