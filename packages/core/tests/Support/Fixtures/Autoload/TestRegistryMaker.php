<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;

final class TestRegistryMaker implements Maker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('tests.registry', 'Registry maker', 'Fixture maker for receipt ownership tests', 'Tests', 'heroicon-o-wrench', false, true);
    }

    public function preview(MakerInputData $input): MakerPreviewData
    {
        return new MakerPreviewData($input->maker, collect(), collect(), collect(), collect());
    }

    public function run(MakerInputData $input): MakerResultData
    {
        return new MakerResultData($input->maker, collect(), collect(), collect(), collect(), true);
    }
}
