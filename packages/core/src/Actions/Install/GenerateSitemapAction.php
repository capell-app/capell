<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class GenerateSitemapAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step('Generating XML sitemaps…');
        RunArtisanCommandAction::run('capell:xml-sitemap', reporter: $reporter, silent: true);
        $reporter->report('✓ Sitemaps generated');
    }
}
