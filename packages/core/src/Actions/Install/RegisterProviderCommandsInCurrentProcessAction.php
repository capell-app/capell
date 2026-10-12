<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Closure;
use Illuminate\Console\Application;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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

        if (! app()->isBooted()) {
            return $result;
        }

        // Before the kernel builds Artisan, these declarations will be picked
        // up normally. Building it here can re-enter an unfinished bootstrap.
        $kernel = Artisan::getFacadeRoot();
        if (! $kernel instanceof Kernel
            || ! new ReflectionProperty(Kernel::class, 'artisan')->getValue($kernel) instanceof Application) {
            return $result;
        }

        foreach ($after as $bootstrapper) {
            if (in_array($bootstrapper, $before, true)) {
                continue;
            }

            // Laravel captures this list in ServiceProvider::commands(). Read
            // only new declarations: replaying arbitrary starting callbacks
            // would repeat unrelated console bootstrap side effects.
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

            RegisterCommandsInCurrentProcessAction::run($commandClasses);
        }

        return $result;
    }
}
