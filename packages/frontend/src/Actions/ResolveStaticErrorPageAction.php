<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Enums\StaticErrorPageResolutionReason;
use Capell\Frontend\Support\Error\StaticErrorPageMatcher;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?string run(string $scheme, string $host, string $pathInfo, string $status)
 */
final class ResolveStaticErrorPageAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly StaticErrorPageMatcher $matcher,
    ) {}

    public function handle(string $scheme, string $host, string $pathInfo, string $status): ?string
    {
        if (! app()->bound(StaticErrorPageStore::class)) {
            return $this->fail(StaticErrorPageResolutionReason::StoreUnbound, $scheme, $host, $pathInfo, $status, 0);
        }

        $entries = $this->matcher->entries();

        if ($entries === []) {
            return $this->fail(StaticErrorPageResolutionReason::ManifestEmpty, $scheme, $host, $pathInfo, $status, 0);
        }

        $bestEntry = null;
        $bestMatchLength = -1;
        $closestRejection = null;

        foreach ($entries as $entry) {
            $rejection = $this->matcher->reject($entry, $scheme, $host, $pathInfo, $status);

            if ($rejection instanceof StaticErrorPageResolutionReason) {
                if ($closestRejection === null || $rejection->specificity() > $closestRejection->specificity()) {
                    $closestRejection = $rejection;
                }

                continue;
            }

            $matchLength = $this->matcher->matchLength($this->matcher->entryPath($entry));

            if ($matchLength > $bestMatchLength) {
                $bestEntry = $entry;
                $bestMatchLength = $matchLength;
            }
        }

        if ($bestEntry === null) {
            return $this->fail(
                $closestRejection ?? StaticErrorPageResolutionReason::ManifestEmpty,
                $scheme,
                $host,
                $pathInfo,
                $status,
                count($entries),
            );
        }

        $path = resolve(StaticErrorPageStore::class)->path($this->matcher->entryFile($bestEntry));

        if ($path === null) {
            return $this->fail(StaticErrorPageResolutionReason::FileUnresolved, $scheme, $host, $pathInfo, $status, count($entries));
        }

        if (! is_file($path)) {
            return $this->fail(StaticErrorPageResolutionReason::FileMissing, $scheme, $host, $pathInfo, $status, count($entries));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return $this->fail(StaticErrorPageResolutionReason::FileUnreadable, $scheme, $host, $pathInfo, $status, count($entries));
        }

        return $contents;
    }

    /**
     * Record why the resolution failed, then preserve the `?string` contract.
     *
     * Debug level and limited to the four inputs plus a coded reason: no file
     * contents, no signed URLs, no authoring state. The happy path never
     * reaches here, so a successful resolution logs nothing at all.
     */
    private function fail(
        StaticErrorPageResolutionReason $reason,
        string $scheme,
        string $host,
        string $pathInfo,
        string $status,
        int $entriesConsidered,
    ): null {
        Log::debug('Static error page did not resolve.', [
            'scheme' => $scheme,
            'host' => $host,
            'path' => $pathInfo,
            'status' => $status,
            'reason' => $reason->value,
            'entries_considered' => $entriesConsidered,
        ]);

        return null;
    }
}
