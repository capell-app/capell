<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;

final class CoreSchemaMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.schema', 'Schema', 'Create a Capell schema class', 'Core', 'heroicon-o-rectangle-stack', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = $this->studlyName($input);

        return $this->previewData(
            $input,
            collect([$this->fileData(app_path('Schemas/' . $name . 'Schema.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/schema.stub', ['class' => $name]), $input->force)]),
            collect(['php artisan capell:make-schema ' . $name]),
            collect(['Register the schema through CapellCore::registerSchema().']),
        );
    }
}
