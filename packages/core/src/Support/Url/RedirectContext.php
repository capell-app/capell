<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

/**
 * What a stored or followed destination may point at.
 *
 * Absolute targets must use one of the allowed schemes. When allowed hosts are given,
 * an absolute target must name one of them exactly (case-insensitive), so a package can
 * require the site's own domains. Relative targets are same-origin paths beginning with
 * a single slash.
 */
final readonly class RedirectContext
{
    /** @var list<string> */
    public array $schemes;

    /** @var list<string>|null */
    public ?array $allowedHosts;

    /**
     * @param  list<string>  $schemes
     * @param  list<string>|null  $allowedHosts
     */
    public function __construct(
        array $schemes = ['https', 'http'],
        public bool $allowRelative = true,
        ?array $allowedHosts = null,
    ) {
        $this->schemes = array_values(array_map(strtolower(...), $schemes));
        $this->allowedHosts = $allowedHosts === null ? null : array_values(array_map(strtolower(...), $allowedHosts));
    }

    /**
     * @param  list<string>  $hosts
     */
    public static function sameHost(array $hosts, bool $allowRelative = true): self
    {
        return new self(allowRelative: $allowRelative, allowedHosts: $hosts);
    }
}
