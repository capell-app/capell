<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;

final class ActionMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData(
            key: 'core.action',
            label: 'Action',
            description: 'Create a Capell action class',
            group: 'Core',
            icon: 'heroicon-o-bolt',
            supportsDatabaseWrites: false,
            supportsPhpWrites: true,
        );
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = $this->studlyName($input);
        $actionClass = $name . 'Action';
        $files = collect([
            $this->fileData(
                app_path('Actions/' . $actionClass . '.php'),
                $this->renderStub(__DIR__ . '/../../../../stubs/makers/action.stub', ['class' => $name]),
                $input->force,
            ),
        ]);

        if (($input->values['data'] ?? false) === true) {
            $files->push($this->fileData(
                app_path('Data/' . $name . 'Data.php'),
                $this->renderStub(__DIR__ . '/../../../../stubs/makers/action-data.stub', ['class' => $name]),
                $input->force,
            ));
        }

        return $this->previewData(
            $input,
            $files,
            collect(['php artisan capell:make-action ' . $name . (($input->values['data'] ?? false) === true ? ' --data' : '')]),
            collect(['Action will be auto-loadable by Composer.']),
        );
    }
}
