<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Enums\HtmlSanitizerProfile;
use InvalidArgumentException;

/**
 * Holds the package extensions to Core's HTML sanitiser profiles. Packages
 * register extensions while booting; the resolved policies are cached until the
 * next registration. Embed inherits every Rich text extension.
 */
final class HtmlSanitizerProfileRegistry
{
    /** @var array<string, list<HtmlSanitizerProfileExtension>> */
    private array $extensions = [];

    /** @var array<string, HtmlSanitizerPolicy> */
    private array $policies = [];

    public function extend(HtmlSanitizerProfile $profile, HtmlSanitizerProfileExtension $extension): void
    {
        throw_unless($profile->isExtensible(), InvalidArgumentException::class, sprintf('The [%s] sanitiser profile cannot be extended.', $profile->value));
        throw_if(
            $extension->embedHosts !== [] && $profile !== HtmlSanitizerProfile::Embed,
            InvalidArgumentException::class,
            'Only the embed profile accepts embed hosts.',
        );

        foreach (array_keys($extension->embedHosts) as $host) {
            throw_if(
                in_array($host, SiteOwnedHosts::configured(), true),
                InvalidArgumentException::class,
                sprintf('[%s] serves this site or its assets; a sandboxed frame on the same origin can remove its own sandbox.', $host),
            );
        }

        $this->extensions[$profile->value][] = $extension;
        $this->policies = [];
    }

    public function policy(HtmlSanitizerProfile $profile): HtmlSanitizerPolicy
    {
        return $this->policies[$profile->value] ??= $this->resolve($profile);
    }

    private function resolve(HtmlSanitizerProfile $profile): HtmlSanitizerPolicy
    {
        $policy = HtmlSanitizerPolicy::for($profile);
        $inherited = $profile === HtmlSanitizerProfile::Embed ? $this->extensions[HtmlSanitizerProfile::RichText->value] ?? [] : [];

        foreach ([...$inherited, ...$this->extensions[$profile->value] ?? []] as $extension) {
            $policy = $policy->extendedWith($extension);
        }

        return $policy;
    }
}
