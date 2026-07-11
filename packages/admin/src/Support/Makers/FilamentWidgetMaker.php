<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Makers;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

class FilamentWidgetMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('admin.filament-widget', 'Filament Widget', 'Create a custom admin content builder widget', 'Admin', 'heroicon-o-squares-2x2', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $class = $this->studlyName($input, 'Widget');
        $widgetName = Str::kebab(Str::beforeLast($class, 'Widget'));

        return $this->previewData(
            $input,
            collect([$this->fileData(app_path('Filament/Widgets/' . $class . '.php'), $this->renderStub(__DIR__ . '/../../../stubs/makers/filament-widget.stub', [
                'namespace' => 'App\\Filament\\Widgets',
                'class' => $class,
                'widgetName' => $widgetName,
                'translationKey' => 'capell-admin::widget.' . $widgetName,
            ]), $input->force)]),
            collect(['php artisan capell:make admin.filament-widget --name="' . ($input->values['name'] ?? $class) . '"']),
            collect([
                'Host-app widgets are discovered from App\\Filament\\Widgets.',
                'Run php artisan capell:admin-cache-widgets after creating new widgets.',
            ]),
        );
    }
}
