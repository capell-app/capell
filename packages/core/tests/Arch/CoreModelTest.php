<?php

declare(strict_types=1);

use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasPublishDates;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasTranslations;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Translatable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

arch('System package to have models')
    ->expect('Capell\Core\Models')
    ->classes()
    ->toExtend(Model::class)
    ->ignoring([
        'Capell\Core\Models\Casts',
        'Capell\Core\Models\Concerns',
        'Capell\Core\Models\Contracts',
        'Capell\Core\Models\Scopes',
    ]);

arch('System package to have factories')
    ->expect('Capell\Core\Models')
    ->classes()
    ->not()->toHaveSuffix('Model');

it('core database factories extend Laravel factories and define state', function (): void {
    $factoryPath = realpath(__DIR__ . '/../../../../packages/core/database/factories');

    throw_if(in_array($factoryPath, ['', '0', false], true) || ! is_dir($factoryPath), RuntimeException::class, 'Factories path does not exist: ' . $factoryPath);

    $factoryFiles = collect(scandir($factoryPath))
        ->filter(fn (string $file): bool => str_ends_with($file, 'Factory.php'));

    foreach ($factoryFiles as $factoryFile) {
        $factoryClass = 'Capell\Core\Database\Factories\\' . pathinfo($factoryFile, PATHINFO_FILENAME);

        expect($factoryClass)
            ->toExtend(Factory::class)
            ->toHaveMethod('definition');
    }
});

arch('traits in Models\Concerns namespace')
    ->expect('Capell\Core\Models\Concerns')
    ->classes()
    ->toBeTraits();

arch('models using a trait must implement an interface')
    ->expect(function (string $trait, string $interface, string $modelsNamespace = 'Capell\Core\Models'): void {
        $modelsPath = realpath(__DIR__ . '/../../../../packages/core/src/Models');

        throw_if(in_array($modelsPath, ['', '0', false], true) || ! is_dir($modelsPath), RuntimeException::class, 'Models path does not exist: ' . $modelsPath);

        $modelFiles = collect(scandir($modelsPath))
            ->filter(fn (string $file): bool => str_ends_with($file, '.php'));

        foreach ($modelFiles as $file) {
            $className = $modelsNamespace . '\\' . pathinfo($file, PATHINFO_FILENAME);

            if (is_subclass_of($className, Model::class)) {
                $usesTrait = in_array($trait, class_uses($className), true);
                $implementsInterface = in_array($interface, class_implements($className), true);

                if ($usesTrait) {
                    expect($implementsInterface)->toBeTrue(sprintf('%s uses %s but does not implement %s', $className, $trait, $interface));
                }
            }
        }
    })
    ->with([
        'status' => [HasStatus::class, Statusable::class],
        'translations' => [HasTranslations::class, Translatable::class],
        'publish dates' => [HasPublishDates::class, Publishable::class],
        'default' => [HasDefault::class, Defaultable::class],
    ]);
