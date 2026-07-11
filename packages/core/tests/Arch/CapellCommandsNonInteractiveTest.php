<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Arch;

use AssertionError;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Throwable;

it('all capell commands fail gracefully in non-interactive mode', function (): void {
    $kernel = resolve(Kernel::class);
    $allCommands = $kernel->all();

    // Filter to capell:* commands
    $capellCommands = collect($allCommands)
        ->filter(function ($command): bool {
            if (! $command instanceof Command) {
                return false;
            }

            return str_starts_with($command->getName() ?? '', 'capell:');
        })
        ->values();

    // Skiplist: commands that require fixture state and cannot be exercised without interaction.
    $skiplist = [
        'capell:install',           // Creates core tables
        'capell:make-schema',       // Could be interactive-required
    ];

    // Filter out skipped commands
    $commandsToTest = $capellCommands
        ->reject(fn (Command $command): bool => in_array($command->getName() ?? '', $skiplist, true))
        ->reject(fn (Command $command): bool => capellCommandHasRequiredArguments($command))
        ->map(fn (Command $command): string => $command->getName() ?? '')
        ->all();

    expect($commandsToTest)->not()->toBeEmpty('No capell commands found to test');

    foreach ($commandsToTest as $commandName) {
        $exception = null;
        $exitCode = null;

        try {
            $exitCode = Artisan::call($commandName, ['--no-interaction' => true]);
        } catch (Throwable $thrown) {
            $exception = $thrown;
        }

        // Should either succeed (0), fail (non-0), or throw a RuntimeException
        // but NEVER throw a NonInteractiveValidationException
        if ($exception instanceof Throwable) {
            expect($exception)
                ->not()->toBeInstanceOf(
                    \Symfony\Component\Console\Exception\RuntimeException::class,
                    sprintf('Command %s threw non-interactive validation error: ', $commandName) . $exception->getMessage(),
                );

            // Acceptable: our thrown RuntimeException with actionable message
            if (! ($exception instanceof RuntimeException)) {
                throw new AssertionError(
                    sprintf('Command %s threw unexpected exception type: ', $commandName) . $exception::class
                    . "\n" . $exception->getMessage(),
                );
            }
        } else {
            // No exception: exit code should be success or known failure
            expect($exitCode)->toBeGreaterThanOrEqual(0)
                ->and($exitCode)->toBeLessThanOrEqual(1);
        }
    }
})->group('arch');

function capellCommandHasRequiredArguments(Command $command): bool
{
    return collect($command->getDefinition()->getArguments())
        ->contains(fn (InputArgument $argument): bool => $argument->isRequired());
}
