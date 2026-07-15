<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Contracts\FrontendResourceSourceData;
use Capell\Frontend\Enums\CrossOrigin;
use Capell\Frontend\Enums\ReferrerPolicy;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class ExternalResourceSourceData extends Data implements FrontendResourceSourceData
{
    public readonly ?CrossOrigin $crossOrigin;

    public function __construct(
        public readonly string $httpsUrl,
        public readonly ?string $integrity = null,
        ?CrossOrigin $crossOrigin = null,
        public readonly ?ReferrerPolicy $referrerPolicy = null,
    ) {
        $this->assertValidUrl();
        $this->assertValidIntegrity();
        $this->crossOrigin = $integrity !== null ? ($crossOrigin ?? CrossOrigin::Anonymous) : $crossOrigin;
    }

    private function assertValidUrl(): void
    {
        $parts = parse_url($this->httpsUrl);

        throw_if(! is_array($parts)
        || ($parts['scheme'] ?? null) !== 'https'
        || ! isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || filter_var($this->httpsUrl, FILTER_VALIDATE_URL) === false, InvalidArgumentException::class, 'External resources must use an absolute HTTPS URL without credentials or fragments.');
    }

    private function assertValidIntegrity(): void
    {
        if ($this->integrity === null) {
            return;
        }

        $values = preg_split('/\s+/', trim($this->integrity));

        throw_if($values === false || $values === [], InvalidArgumentException::class, 'External resource integrity must contain a valid SRI hash.');

        foreach ($values as $value) {
            throw_if(preg_match('/\Asha(?:256|384|512)-[A-Za-z0-9+\/]+={0,2}\z/', $value) !== 1, InvalidArgumentException::class, 'External resource integrity must contain only sha256, sha384, or sha512 hashes.');
        }
    }
}
