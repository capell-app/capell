<?php

declare(strict_types=1);

namespace Capell\Core\Data\Backup;

use Spatie\LaravelData\Data;

final class BackupManifestSignatureData extends Data
{
    public function __construct(
        public readonly string $algorithm,
        public readonly string $keyId,
        public readonly string $value,
    ) {}
}
