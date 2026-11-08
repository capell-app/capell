<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Makers\MakerRegistry;
use Capell\Core\Tests\Support\Fixtures\Autoload\TestRegistryMaker;
use Illuminate\Support\Collection;

function fakeMakerForRegistryTest(string $key, string $group = 'Tests', string $label = 'Fake maker'): Maker
{
    return new readonly class($key, $group, $label) implements Maker
    {
        public function __construct(
            private string $key,
            private string $group,
            private string $label,
        ) {}

        public function definition(): MakerDefinitionData
        {
            return new MakerDefinitionData($this->key, $this->label, 'Fake maker for tests', $this->group, 'heroicon-o-wrench', false, true);
        }

        public function preview(MakerInputData $input): MakerPreviewData
        {
            return new MakerPreviewData($input->maker, collect(), collect(), collect(), collect());
        }

        public function run(MakerInputData $input): MakerResultData
        {
            return new MakerResultData($input->maker, collect(), collect(), collect(), collect(), true);
        }
    };
}

it('registers and resolves makers by key', function (): void {
    $registry = new MakerRegistry;
    $registry->register(fakeMakerForRegistryTest('core.action'));

    expect($registry->has('core.action'))->toBeTrue();
    expect($registry->get('core.action')->definition()->key)->toBe('core.action');
});

it('returns all makers sorted by group and label', function (): void {
    $registry = new MakerRegistry;
    $registry->register(fakeMakerForRegistryTest('content.block', 'Content', 'Block'));
    $registry->register(fakeMakerForRegistryTest('core.action', 'Core', 'Action'));

    expect($registry->all())->toBeInstanceOf(Collection::class);
    expect($registry->all()->map(fn (Maker $maker): string => $maker->definition()->key)->all())
        ->toBe(['content.block', 'core.action']);
});

function makerReceiptRegistry(): ExtensionContributionReceiptRegistry
{
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(RecordsExtensionContributionReceipt::class, $receipts);

    return $receipts;
}

it('keeps maker receipts owned by the declaring package when another package activation resolves the registry', function (): void {
    $receipts = makerReceiptRegistry();
    $receipts->rememberNamespaceContext(
        'Capell\\Core\\Tests\\Support\\Fixtures\\Autoload',
        ExtensionContributionReceiptContext::forPackage('vendor/declaring', 'runtime', 'Vendor\\Declaring\\Provider'),
    );

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/resolving', 'runtime', 'Vendor\\Resolving\\Provider'),
        function (): void {
            (new MakerRegistry)->register(new TestRegistryMaker);
        },
    );

    expect($receipts->forPackage('vendor/resolving'))->toBe([])
        ->and($receipts->forPackage('vendor/declaring'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/declaring')[0]->implementation)->toBe(TestRegistryMaker::class);
});

it('keeps Core-namespaced makers owned by Core when another package activation resolves the registry', function (): void {
    $receipts = makerReceiptRegistry();
    $receipts->rememberNamespaceContext(
        'Capell\\Core\\',
        ExtensionContributionReceiptContext::forPackage('capell-app/core', 'runtime', 'Capell\\Core\\CoreServiceProvider'),
    );

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/resolving', 'runtime', 'Vendor\\Resolving\\Provider'),
        function (): void {
            (new MakerRegistry)->register(new TestRegistryMaker);
        },
    );

    expect($receipts->forPackage('vendor/resolving'))->toBe([])
        ->and($receipts->forPackage('capell-app/core'))->toHaveCount(1);
});

it('attributes anonymous maker receipts to the active package, not the interface namespace', function (): void {
    $receipts = makerReceiptRegistry();
    $receipts->rememberNamespaceContext(
        'Capell\\Core\\',
        ExtensionContributionReceiptContext::forPackage('capell-app/core', 'runtime', 'Capell\\Core\\CoreServiceProvider'),
    );

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/active', 'runtime', 'Vendor\\Active\\Provider'),
        function (): void {
            (new MakerRegistry)->register(fakeMakerForRegistryTest('anonymous.maker'));
        },
    );

    expect($receipts->forPackage('capell-app/core'))->toBe([])
        ->and($receipts->forPackage('vendor/active'))->toHaveCount(1);
});
