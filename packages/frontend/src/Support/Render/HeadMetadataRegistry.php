<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use InvalidArgumentException;

final class HeadMetadataRegistry
{
    private const KEYS = ['og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'twitter:card', 'twitter:title', 'twitter:description', 'jsonld:WebSite', 'jsonld:WebPage', 'robots'];

    /** @var array<string, string|array<string, mixed>> */
    private array $values = [];

    public static function mergeRobots(string ...$contributions): string
    {
        $tokens = [];
        foreach ($contributions as $contribution) {
            foreach (explode(',', strtolower($contribution)) as $token) {
                $token = trim($token);
                if ($token !== '' && ! in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                }
            }
        }

        if (in_array('none', $tokens, true) || in_array('noindex', $tokens, true)) {
            $tokens = array_values(array_diff($tokens, ['index']));
        }

        if (in_array('none', $tokens, true) || in_array('nofollow', $tokens, true)) {
            $tokens = array_values(array_diff($tokens, ['follow']));
        }

        return implode(', ', $tokens);
    }

    /** @param string|array<string, mixed> $value */
    public function register(string $key, string|array $value): void
    {
        throw_if(! in_array($key, self::KEYS, true) || (str_starts_with($key, 'jsonld:') !== is_array($value)), InvalidArgumentException::class, 'Invalid head metadata contribution: ' . $key);

        $this->values[$key] = $key === 'robots' && is_string($value) && isset($this->values[$key]) && is_string($this->values[$key])
            ? self::mergeRobots($this->values[$key], $value)
            : $value;
    }

    /** @param array<string, string|array<string, mixed>> $defaults
     * @return array<string, string|array<string, mixed>>
     */
    public function apply(array $defaults): array
    {
        $values = array_replace($defaults, $this->values);
        if (isset($defaults['robots'], $this->values['robots']) && is_string($defaults['robots']) && is_string($this->values['robots'])) {
            $values['robots'] = self::mergeRobots($defaults['robots'], $this->values['robots']);
        }

        return $values;
    }
}
