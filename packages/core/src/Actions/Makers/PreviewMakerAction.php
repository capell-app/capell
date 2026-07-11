<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Makers;

use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Lorisleiva\Actions\Concerns\AsAction;

class PreviewMakerAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $values
     */
    public function handle(
        string $maker,
        array $values,
        bool $force = false,
        bool $databaseWrites = false,
    ): MakerPreviewData {
        $input = new MakerInputData(
            maker: $maker,
            values: $values,
            dryRun: true,
            force: $force,
            databaseWrites: $databaseWrites,
        );

        return resolve(MakerRegistryInterface::class)->get($maker)->preview($input);
    }
}
