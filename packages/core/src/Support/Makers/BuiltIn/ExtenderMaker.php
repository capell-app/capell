<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

class ExtenderMaker extends AbstractFileMaker
{
    /** @var array<string, string> */
    private array $validHooks = [
        'BeforeTitle' => 'Before Title',
        'AfterTitle' => 'After Title',
        'AfterContentEditor' => 'After Content Editor',
        'AfterExtraContent' => 'After Extra Content',
        'BeforeSearchMeta' => 'Before Search Meta',
        'AfterSearchMeta' => 'After Search Meta',
    ];

    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.extender', 'Page Schema Extender', 'Create a page schema extender', 'Core', 'heroicon-o-puzzle-piece', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = $this->studlyName($input);
        $hook = Str::studly((string) ($input->values['hook'] ?? 'AfterTitle'));
        $hookLabel = $this->validHooks[$hook] ?? $this->validHooks['AfterTitle'];
        $hook = array_key_exists($hook, $this->validHooks) ? $hook : 'AfterTitle';

        return $this->previewData(
            $input,
            collect([$this->fileData(app_path('Extenders/' . $name . 'Extender.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/extender.stub', ['class' => $name, 'hook' => $hook, 'hookLabel' => $hookLabel]), $input->force)]),
            collect(['php artisan capell:make-extender ' . $name . ' ' . $hook]),
            collect([
                'Declare the extender as a contribution in the package manifest (capell.json).',
                'Register it through the canonical registrar in your service provider: '
                    . '$this->surface() for core surfaces, the AdminBridgeRegistrar for admin surfaces, '
                    . 'or the FrontendHookRegistrar for render hooks. For a page schema extender, '
                    . 'tag it via PageSchemaExtender::TAG — never a raw tag string.',
                'Add a focused harness test asserting the extender is resolved and applied.',
            ]),
        );
    }
}
