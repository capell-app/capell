<?php

declare(strict_types=1);

namespace Capell\Core\Settings;

use Capell\Core\Contracts\SettingsContract;
use Spatie\LaravelSettings\Settings;

/**
 * First-run milestones, recorded once per site so the admin can tell a site
 * being set up from one that has already gone live, even if its home page is
 * unpublished later. Read and write through SitePublicationMilestones.
 */
class FirstRunSettings extends Settings implements SettingsContract
{
    /** @var array<int|string, string> site id => ISO-8601 time its home page first went public */
    public array $published_sites = [];

    public static function group(): string
    {
        return 'first_run';
    }
}
