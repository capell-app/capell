<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Concerns;

use Capell\Tests\AbstractTestCase;
use Illuminate\Support\Facades\App;

/**
 * @mixin AbstractTestCase
 */
trait TestingFrontend
{
    public function setUpTestingFrontend(): void
    {
        if (! App::environment('testing')) {
            return;
        }

        // Disable HTML cache during tests to avoid stale content
        // Config::set('capell-frontend.html_cache', false);

        // Clear page cache storage if present
        /*try {
            $pageCache = resolve(PageCacheService::class);
            if ($pageCache->exists('')) {
                $pageCache->deleteDirectory('/');
            }
        } catch (Throwable) {
            // ignore
        }*/

        // Optionally could register routes if needed
        // \Capell\Frontend\Helpers\Routes::routes();

        $this->withoutVite();
    }
}
