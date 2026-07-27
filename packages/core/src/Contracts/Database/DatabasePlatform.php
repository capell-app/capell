<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Database;

use Capell\Core\Enums\Database\DatabaseFamily;

interface DatabasePlatform
{
    public const string TAG = 'capell.database.platform';

    /**
     * @return non-empty-list<non-empty-string>
     */
    public function drivers(): array;

    public function family(): DatabaseFamily;

    public function phpExtension(): string;

    public function queryDialect(): DatabaseQueryDialect;

    public function schemaDialect(): DatabaseSchemaDialect;

    public function provisioner(): ?DatabaseProvisioner;
}
