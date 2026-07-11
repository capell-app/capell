<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsObject;

final class GenerateSitemapAction
{
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step('Generating XML sitemaps…');
        Artisan::call('capell:xml-sitemap');
        $reporter->report('✓ Sitemaps generated');
    }
}
