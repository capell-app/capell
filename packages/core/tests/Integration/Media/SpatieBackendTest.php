<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Media;

use Capell\Core\Models\Media;

final class SpatieBackendTest extends MediaBackendTestCase
{
    protected function activateBackend(): void
    {
        config()->set('capell.media.backend', 'spatie');
        config()->set('capell.media.model', Media::class);
    }
}
