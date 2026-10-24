<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Makers\RunMakerFilamentAction;
use Capell\Admin\Filament\Components\Forms\ComponentSelect;
use Capell\Admin\Filament\Components\Forms\ConfiguratorSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Tests\Support\FilamentNotificationSnapshots;
use Filament\Actions\Action;
use Filament\Schemas\Schema;

it('renders every generated maker path as plain text', function (string $kind, bool $successful): void {
    $paths = [
        'resources/views/<b>oops</b>.blade.php',
        'app/<em>second & "quoted"</em>.php',
    ];
    bindFakeAction(RunMakerAction::class, new MakerResultData(
        maker: 'admin.configurator',
        files: collect(array_map(
            fn (string $path): MakerFileData => new MakerFileData($path, 'create', false, true),
            $paths,
        )),
        databaseRecords: collect(),
        commands: collect(),
        notes: collect(),
        successful: $successful,
    ));

    makerNotificationAction($kind)->call(['data' => ['name' => '<b>oops</b>']]);

    $notification = FilamentNotificationSnapshots::pull()->sole();
    $body = implode(PHP_EOL, $paths);

    assertMakerNotificationText($notification->toHtml(), $body);
    expect($notification->getStatus())->toBe($successful ? 'success' : 'danger')
        ->and($notification->getBody())->toBe(e($body));
})->with([
    'component success' => ['component', true],
    'configurator success' => ['configurator', true],
    'maker success' => ['maker', true],
    'maker unsuccessful result' => ['maker', false],
]);

it('renders maker exceptions as plain text and preserves the failure', function (string $kind): void {
    $message = 'Cannot create <b>oops</b>: <em>second & "quoted"</em>';
    app()->bind(RunMakerAction::class, fn (): object => new readonly class($message)
    {
        public function __construct(private string $message) {}

        public function handle(MakerInputData $input): MakerResultData
        {
            throw new RuntimeException($this->message);
        }
    });

    $action = makerNotificationAction($kind);

    expect(fn (): mixed => $action->call(['data' => ['name' => '<b>oops</b>']]))
        ->toThrow(RuntimeException::class, $message);

    $notification = FilamentNotificationSnapshots::pull()->sole();

    assertMakerNotificationText($notification->toHtml(), $message);
    expect($notification->getStatus())->toBe('danger')
        ->and($notification->getBody())->toBe(e($message));
})->with(['component', 'configurator', 'maker']);

function makerNotificationAction(string $kind): Action
{
    if ($kind === 'maker') {
        return RunMakerFilamentAction::make('admin.configurator');
    }

    $component = $kind === 'component'
        ? ComponentSelect::make('selected')->withCreateComponentAction('page')
        : ConfiguratorSelect::make('selected')->withCreateConfiguratorAction('Page');
    $schema = Schema::make(Livewire::make())->statePath('data')->components([$component]);
    $schema->fill(['selected' => null]);

    return $component->getSuffixActions()[$kind === 'component' ? 'createComponent' : 'createConfigurator'];
}

function assertMakerNotificationText(string $html, string $body): void
{
    throw_if($html === '', RuntimeException::class, 'Expected rendered notification HTML.');

    $dom = new DOMDocument;

    expect(@$dom->loadHTML($html))->toBeTrue()
        ->and($dom->getElementsByTagName('b')->length)->toBe(0)
        ->and($dom->getElementsByTagName('em')->length)->toBe(0)
        ->and($dom->textContent)->toContain($body);
}
