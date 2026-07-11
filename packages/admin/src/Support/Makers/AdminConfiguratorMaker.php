<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Makers;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

class AdminConfiguratorMaker extends AbstractFileMaker
{
    public function __construct(
        private readonly ConfiguratorSourceResolver $sources,
    ) {}

    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('admin.configurator', 'Admin Configurator', 'Create a host-app Filament type configurator', 'Admin', 'heroicon-o-squares-plus', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $configuratorType = Str::studly((string) ($input->values['type'] ?? 'Pages'));
        $class = $this->studlyName($input, 'Configurator');
        $source = $this->sources->resolve($configuratorType, isset($input->values['source']) ? (string) $input->values['source'] : null);
        $namespace = 'App\\Filament\\Configurators\\' . $configuratorType;
        $path = app_path('Filament/Configurators/' . $configuratorType . '/' . $class . '.php');
        $contents = $this->configuratorContents($source['path'], $namespace, $class, $configuratorType);

        return $this->previewData(
            $input,
            collect([$this->fileData($path, $contents, $input->force)]),
            collect(['php artisan capell:make admin.configurator --type=' . $configuratorType . ' --name=' . $class]),
            collect(['Run php artisan capell:admin-cache-configurators after creating new configurators.']),
        );
    }

    private function configuratorContents(?string $sourcePath, string $namespace, string $class, string $configuratorType): string
    {
        if ($sourcePath !== null && file_exists($sourcePath)) {
            $contents = (string) file_get_contents($sourcePath);
            $contents = preg_replace('/^<\?php\s*(declare\(strict_types=1\);\s*)?/s', "<?php\n\ndeclare(strict_types=1);\n\n", $contents) ?? $contents;
            $contents = preg_replace('/namespace\s+[^;]+;/', 'namespace ' . $namespace . ';', $contents, 1) ?? $contents;

            return preg_replace('/class\s+\w+/', 'class ' . $class, $contents, 1) ?? $contents;
        }

        return $this->renderStub(__DIR__ . '/../../../stubs/makers/type-configurator-generic.stub', [
            'namespace' => $namespace,
            'class' => $class,
            'configuratorType' => $configuratorType,
            'configuratorBlueprintSubjectEnum' => $this->configuratorBlueprintSubjectEnum($configuratorType),
        ]);
    }

    private function configuratorBlueprintSubjectEnum(string $configuratorType): string
    {
        return match ($configuratorType) {
            'Pages' => 'Page',
            'Sites' => 'Site',
            'Languages' => 'Language',
            'Layouts' => 'Layout',
            default => 'Type',
        };
    }
}
