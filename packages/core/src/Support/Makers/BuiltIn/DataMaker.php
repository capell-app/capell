<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;

final class DataMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.data', 'Data', 'Create a Spatie Data class', 'Core', 'heroicon-o-circle-stack', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = $this->studlyName($input);

        return $this->previewData(
            $input,
            collect([
                $this->fileData(app_path('Data/' . $name . 'Data.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/data.stub', ['class' => $name]), $input->force),
                $this->fileData(base_path('tests/Feature/Generated/' . $name . 'DataTest.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/data-test.stub', ['class' => $name]), $input->force),
            ]),
            collect(['php artisan capell:make:data ' . $name]),
            collect(['No registration is required; Composer autoloads the data class.']),
        );
    }
}
