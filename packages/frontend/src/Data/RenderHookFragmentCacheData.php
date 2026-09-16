<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Enums\RenderHookLocation;
use LogicException;

final class RenderHookFragmentCacheData
{
    public const int VERSION = 1;

    /**
     * @param  list<array{stableKey: string, location: string, scenario: string|null, target: string|null, offset: int}>  $fragments
     */
    public function __construct(
        public readonly string $shell,
        public readonly array $fragments,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromMetadata(string $shell, array $metadata): self
    {
        if (($metadata['version'] ?? null) !== self::VERSION) {
            throw new LogicException('Unsupported render hook fragment metadata version.');
        }

        $shellHash = $metadata['shellSha256'] ?? null;

        if (! is_string($shellHash) || ! hash_equals(hash('sha256', $shell), $shellHash)) {
            throw new LogicException('Render hook fragment metadata does not match the cached shell.');
        }

        $rawFragments = $metadata['fragments'] ?? null;

        if (! is_array($rawFragments) || ! array_is_list($rawFragments)) {
            throw new LogicException('Render hook fragment metadata has an invalid fragment list.');
        }

        $fragments = [];
        $previousOffset = 0;
        $hasPreviousOffset = false;

        foreach ($rawFragments as $rawFragment) {
            if (! is_array($rawFragment)
                || ! is_string($rawFragment['stableKey'] ?? null)
                || $rawFragment['stableKey'] === ''
                || ! is_string($rawFragment['location'] ?? null)
                || ! is_int($rawFragment['offset'] ?? null)
                || (($rawFragment['scenario'] ?? null) !== null && ! is_string($rawFragment['scenario']))
                || (($rawFragment['target'] ?? null) !== null && ! is_string($rawFragment['target']))) {
                throw new LogicException('Render hook fragment metadata has an invalid descriptor.');
            }

            $location = RenderHookLocation::tryFrom($rawFragment['location']);

            if (! $location instanceof RenderHookLocation) {
                throw new LogicException('Render hook fragment metadata has an invalid location.');
            }

            $offset = $rawFragment['offset'];

            if ($offset < 0 || $offset > strlen($shell) || ($hasPreviousOffset && $offset < $previousOffset)) {
                throw new LogicException('Render hook fragment metadata has invalid insertion offsets.');
            }

            $fragments[] = [
                'stableKey' => $rawFragment['stableKey'],
                'location' => $location->value,
                'scenario' => $rawFragment['scenario'] ?? null,
                'target' => $rawFragment['target'] ?? null,
                'offset' => $offset,
            ];
            $previousOffset = $offset;
            $hasPreviousOffset = true;
        }

        return new self($shell, $fragments);
    }

    /**
     * @return array{version: 1, shellSha256: string, fragments: list<array{stableKey: string, location: string, scenario: string|null, target: string|null, offset: int}>}
     */
    public function metadata(): array
    {
        return [
            'version' => self::VERSION,
            'shellSha256' => hash('sha256', $this->shell),
            'fragments' => $this->fragments,
        ];
    }
}
