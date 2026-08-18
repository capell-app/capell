<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface ActivitySettingsReader
{
    public function collectionEnabled(): bool;

    public function searchCollectionEnabled(): bool;

    public function retentionDays(): int;

    public function visitorRetentionDays(): int;
}
