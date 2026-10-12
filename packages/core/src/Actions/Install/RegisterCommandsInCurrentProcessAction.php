<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Illuminate\Console\Application;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;

final class RegisterCommandsInCurrentProcessAction
{
    use AsObject;

    /** @param iterable<class-string<Command>|Command> $commands */
    public function handle(iterable $commands): void
    {
        foreach ($commands as $command) {
            $instance = is_string($command) ? app()->make($command) : $command;
            $name = $instance->getName();

            if ($name !== null && $this->isRegistered($name)) {
                continue;
            }

            Artisan::registerCommand($instance);
        }
    }

    private function isRegistered(string $name): bool
    {
        $kernel = Artisan::getFacadeRoot();
        $application = $kernel instanceof Kernel ? new ReflectionProperty(Kernel::class, 'artisan')->getValue($kernel) : null;

        // Symfony answers has() from its lazy command map without constructing
        // every registered command, which Artisan::all() would do on each call.
        if ($application instanceof Application) {
            return $application->has($name);
        }

        return array_key_exists($name, Artisan::all());
    }
}
