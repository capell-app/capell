<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Support\Media\AdminUploadGrantRepository;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PruneAdminUploadGrantsAction
{
    use AsFake;
    use AsObject;

    public function handle(int $limit = 1000): int
    {
        return resolve(AdminUploadGrantRepository::class)->prune($limit);
    }
}
