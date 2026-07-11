<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class HideEmptyRelationManagerOwnerModel extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public function __construct(private readonly bool $relationExists = false)
    {
        parent::__construct();

        $this->setAttribute('id', $relationExists ? 1 : 2);
        $this->exists = true;
    }

    public function siteDomains(): object
    {
        return new readonly class($this->relationExists)
        {
            public function __construct(private bool $exists) {}

            public function exists(): bool
            {
                return $this->exists;
            }
        };
    }
}
