<?php

declare(strict_types=1);

use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Support\Makers\BuiltIn\ActionMaker;
use Capell\Core\Support\Makers\BuiltIn\BlueprintMaker;
use Capell\Core\Support\Makers\BuiltIn\CoreSchemaMaker;
use Capell\Core\Support\Makers\BuiltIn\DataMaker;
use Capell\Core\Support\Makers\BuiltIn\ExtenderMaker;

it('previews an action class with optional data companion', function (): void {
    $preview = resolve(ActionMaker::class)->preview(new MakerInputData(
        maker: 'core.action',
        values: ['name' => 'PublishPage', 'data' => true],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($preview->files));

    expect(collect($preview->files)->pluck('path')->all())
        ->toContain(app_path('Actions/PublishPageAction.php'))
        ->toContain(app_path('Data/PublishPageData.php'));

    expect($file->contents)
        ->toContain('declare(strict_types=1);');
});

it('previews a data class', function (): void {
    $preview = resolve(DataMaker::class)->preview(new MakerInputData('core.data', ['name' => 'Hero'], true, false, false));
    $file = expectPresent(firstDataItem($preview->files));

    expect($file->path)->toBe(app_path('Data/HeroData.php'));
    expect($file->contents)->toContain('declare(strict_types=1);');
});

it('previews a blueprint class without typed class constants', function (): void {
    $preview = resolve(BlueprintMaker::class)->preview(new MakerInputData('core.blueprint', ['name' => 'LandingPage'], true, false, false));
    $file = expectPresent(firstDataItem($preview->files));

    expect($file->path)->toBe(app_path('Blueprints/LandingPageBlueprint.php'));
    expect($file->contents)
        ->toContain('declare(strict_types=1);')
        ->not->toContain('const string');
});

it('previews an extender against the current page schema extension contract', function (): void {
    $preview = resolve(ExtenderMaker::class)->preview(new MakerInputData(
        maker: 'core.extender',
        values: ['name' => 'HeroFields', 'hook' => 'AfterTitle'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($preview->files));

    expect($file->contents)
        ->toContain('extends AbstractPageSchemaExtender')
        ->toContain('extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array')
        ->toContain('if ($hook !== PageTranslationSchemaHookEnum::AfterTitle)')
        ->not->toContain('function hook()')
        ->not->toContain('function fields(')
        ->not->toContain('extendSettingsTabComponents');
});

it('previews a schema using the current Filament schema composition shape', function (): void {
    $preview = resolve(CoreSchemaMaker::class)->preview(new MakerInputData(
        maker: 'core.schema',
        values: ['name' => 'Hero'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($preview->files));

    expect($file->contents)
        ->toContain('public static function make(Schema $schema): array')
        ->not->toContain('AbstractSchema');
    expect($preview->notes->all())
        ->not->toContain('Register the schema through CapellCore::registerSchema().');
});
