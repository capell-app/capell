<?php

declare(strict_types=1);

use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Support\Makers\MakerRegistry;
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
