<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Exceptions\ArtisanCommandNotFoundException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class GenerateSitemapAction
{
    use AsFake;
    use AsObject;

    public const string COMMAND = 'capell:xml-sitemap';

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step(__('capell-core::install.sitemap.generating'));

        try {
            RunArtisanCommandAction::run(self::COMMAND, reporter: $reporter, silent: true);
        } catch (ArtisanCommandNotFoundException) {
            // Sitemaps come from an optional package; without one the install is still complete.
            $reporter->report(__('capell-core::install.sitemap.skipped', ['command' => self::COMMAND]));

            return;
        }

        $reporter->report(__('capell-core::install.sitemap.generated'));
    }
}
