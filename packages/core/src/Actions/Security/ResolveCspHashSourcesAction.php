<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\ProvidesCspHashSources;
use Capell\Core\Data\Security\CspHashSourcesData;
use Capell\Core\Support\Security\CspHashSource;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * Collects the hash sources of every tagged {@see ProvidesCspHashSources}
 * provider, so a host builds one policy that accepts the inline scripts and
 * styles cached inside public HTML.
 */
class ResolveCspHashSourcesAction
{
    use AsFake;
    use AsObject;

    public function handle(): CspHashSourcesData
    {
        $script = [];
        $style = [];
        $seen = [];

        foreach (app()->tagged(ProvidesCspHashSources::TAG) as $provider) {
            // A runtime boot may tag a provider again after an install-time refresh.
            if (isset($seen[$provider::class])) {
                continue;
            }

            $seen[$provider::class] = true;

            try {
                $script = [...$script, ...$this->valid($provider->scriptHashSources())];
                $style = [...$style, ...$this->valid($provider->styleHashSources())];
            } catch (Throwable) {
                // A broken provider must not take down the response headers; its
                // content is then blocked and reported rather than trusted.
                report(new RuntimeException('CSP hash source provider failed: ' . $provider::class));
            }
        }

        return new CspHashSourcesData(
            script: array_values(array_unique($script)),
            style: array_values(array_unique($style)),
        );
    }

    /**
     * @param  array<mixed>  $sources
     * @return list<string>
     */
    private function valid(array $sources): array
    {
        return array_values(array_filter($sources, CspHashSource::isValid(...)));
    }
}
