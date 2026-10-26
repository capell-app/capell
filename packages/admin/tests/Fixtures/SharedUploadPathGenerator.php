<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Override;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

final class SharedUploadPathGenerator extends DefaultPathGenerator
{
    #[Override]
    protected function getBasePath(Media $media): string
    {
        return 'shared';
    }
}
