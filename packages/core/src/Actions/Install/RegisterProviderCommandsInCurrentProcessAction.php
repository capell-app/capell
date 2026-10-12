<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Closure;
use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionFunction;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;

/** Bridges Laravel's deferred ServiceProvider::commands() declarations during installation. */
final class RegisterProviderCommandsInCurrentProcessAction
{
    use AsObject;

    /**
     * @template T
     *
     * @param  Closure(): T  $registerProviders
     * @return T
     */
    public function handle(Closure $registerProviders): mixed
    {
        $bootstrappers = new ReflectionProperty(Application::class, 'bootstrappers');
        /** @var array<array-key, Closure> $before */
        $before = $bootstrappers->getValue();
        $result = $registerProviders();
        /** @var array<array-key, Closure> $after */
        $after = $bootstrappers->getValue();

        $declarations = $this->newDeclarations($before, $after);

        // Ordinary requests register nothing here, and a console kernel that has
        // not been resolved yet will collect the declarations during its normal
        // start-up. Resolving it now would re-run application bootstrap hooks.
        if ($declarations === [] || ! app()->isBooted() || ! app()->resolved(KernelContract::class)) {
            return $result;
        }

        $kernel = resolve(KernelContract::class);
        if (! $kernel instanceof Kernel
            || ! new ReflectionProperty(Kernel::class, 'artisan')->getValue($kernel) instanceof Application) {
            return $result;
        }

        foreach ($declarations as $commands) {
            RegisterCommandsInCurrentProcessAction::run($commands);
        }

        return $result;
    }

    /**
     * Laravel captures the list in ServiceProvider::commands() as a console
     * starting callback. Read only the declarations added while the providers
     * loaded: replaying arbitrary starting callbacks would repeat unrelated
     * console bootstrap side effects.
     *
     * @param  array<array-key, Closure>  $before
     * @param  array<array-key, Closure>  $after
     * @return list<list<class-string<Command>|Command>>
     */
    private function newDeclarations(array $before, array $after): array
    {
        if (count($after) === count($before)) {
            return [];
        }

        $declarations = [];
        foreach ($after as $bootstrapper) {
            if (in_array($bootstrapper, $before, true)) {
                continue;
            }

            $reflection = new ReflectionFunction($bootstrapper);
            if ($reflection->getClosureScopeClass()?->getName() !== ServiceProvider::class) {
                continue;
            }

            $commands = $reflection->getStaticVariables()['commands'] ?? null;
            if (! is_array($commands)) {
                continue;
            }

            $commandClasses = [];
            foreach ($commands as $command) {
                if ($command instanceof Command || (is_string($command) && is_a($command, Command::class, true))) {
                    $commandClasses[] = $command;
                }
            }

            if ($commandClasses !== []) {
                $declarations[] = $commandClasses;
            }
        }

        return $declarations;
    }
}
