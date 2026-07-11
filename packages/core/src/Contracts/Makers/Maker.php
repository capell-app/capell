<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Makers;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;

interface Maker
{
    public function definition(): MakerDefinitionData;

    public function preview(MakerInputData $input): MakerPreviewData;

    public function run(MakerInputData $input): MakerResultData;
}
