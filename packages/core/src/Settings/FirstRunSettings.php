<?php

declare(strict_types=1);

namespace Capell\Core\Settings;

use Capell\Core\Contracts\SettingsContract;
use Carbon\CarbonImmutable;
use Spatie\LaravelSettings\Settings;

/**
 * Install-wide first-run milestones. Each value is written once, when the
 * milestone first happens, so the admin can tell a new install from one that
 * has already been set up and published even if a page is unpublished later.
 */
class FirstRunSettings extends Settings implements SettingsContract
{
    /** The install suite the installer set up, for example a starter kit. */
    public ?string $suite_key = null;

    /** The kind of business chosen during guided setup. */
    public ?string $business_type = null;

    /** The starter content preset guided setup applied. */
    public ?string $preset_key = null;

    public ?CarbonImmutable $setup_completed_at = null;

    public ?CarbonImmutable $published_at = null;

    public static function group(): string
    {
        return 'first_run';
    }
}
