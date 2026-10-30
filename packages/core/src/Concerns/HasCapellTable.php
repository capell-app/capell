<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Support\TableName;
use Override;

trait HasCapellTable
{
    private bool $capellTableWasSet = false;

    #[Override]
    public function getTable(): string
    {
        $table = parent::getTable();

        return $this->capellTableWasSet ? $table : TableName::resolve($table);
    }

    #[Override]
    public function setTable($table): static
    {
        $this->capellTableWasSet = true;

        return parent::setTable($table);
    }
}
