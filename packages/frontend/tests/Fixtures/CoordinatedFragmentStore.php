<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Override;
use RuntimeException;

final class CoordinatedFragmentStore extends FileStore
{
    public function __construct(
        Filesystem $files,
        string $directory,
        private readonly string $barrierDirectory,
        private readonly int $participant,
        private readonly int $participants,
    ) {
        parent::__construct($files, $directory);
    }

    #[Override]
    public function get($key)
    {
        if (is_string($key) && $this->isFragmentValueKey($key)) {
            $this->awaitParticipants('value-read');
        }

        // Only an implementation without the surrogate lock may read the map
        // concurrently; the locked implementation must proceed without waiting.
        if (is_string($key) && str_ends_with($key, ':surrogate:map') && ! $this->lockExists($key)) {
            $this->awaitParticipants('map-before-read');
            $value = parent::get($key);
            $this->awaitParticipants('map-after-read');

            return $value;
        }

        return parent::get($key);
    }

    private function awaitParticipants(string $phase): void
    {
        $ready = $this->barrierDirectory . '/' . $phase . '-' . $this->participant;
        if (! is_file($ready)) {
            file_put_contents($ready, 'ready');
        }

        $deadline = microtime(true) + 10;
        while ($this->readyParticipants($phase) < $this->participants) {
            throw_if(microtime(true) > $deadline, RuntimeException::class, 'Cache race barrier timed out during ' . $phase . '.');

            Sleep::usleep(1_000);
        }
    }

    private function lockExists(string $mapKey): bool
    {
        return is_file($this->path('file-store-lock:fragment:surrogate:lock:' . hash('sha256', $mapKey)));
    }

    private function isFragmentValueKey(string $key): bool
    {
        return str_contains($key, ':value:') || str_contains($key, ':origin-value:');
    }

    private function readyParticipants(string $phase): int
    {
        $ready = 0;
        for ($index = 0; $index < $this->participants; $index++) {
            $ready += is_file($this->barrierDirectory . '/' . $phase . '-' . $index) ? 1 : 0;
        }

        return $ready;
    }
}
