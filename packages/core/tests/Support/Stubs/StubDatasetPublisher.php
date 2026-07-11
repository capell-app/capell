<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Stubs;

use Capell\Core\Support\Dataset\DatasetPublisher;
use Override;

class StubDatasetPublisher extends DatasetPublisher
{
    #[Override]
    public function normalizePath(?string $path): ?string
    {
        return $path !== null ? rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : null;
    }

    #[Override]
    public function validateType(string $type): bool
    {
        return $type === 'migrations' || $type === 'settings';
    }
}
