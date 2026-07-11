<?php

declare(strict_types=1);

use Capell\Frontend\Console\Commands\UpgradeCommand;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

test('runs frontend upgrade command successfully', function (): void {
    $calls = [];
    $mock = new class($calls) extends UpgradeCommand
    {
        public array $calls;

        public function __construct(array &$calls)
        {
            $this->calls = &$calls;
            parent::__construct();
        }

        public function call(mixed $command, array $arguments = []): int
        {
            $this->calls[] = [$command, $arguments];

            return 0;
        }
    };

    $mock->setLaravel(app());
    $mock->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

    $exitCode = $mock->handle();

    expect($exitCode)->toBe(0)
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-migrations'],
        ])
        ->and($calls)->toContain([
            'migrate', [],
        ])
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true],
        ])
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true],
        ]);
});
