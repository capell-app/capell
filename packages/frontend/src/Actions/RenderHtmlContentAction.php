<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Support\SafeHtml;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * @method static SafeHtml run(string $content, array $context = [])
 */
class RenderHtmlContentAction
{
    use AsFake;
    use AsObject;

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(string $content, array $context = []): SafeHtml
    {
        return SafeHtml::sanitize(
            $this->interpolateTokens($content, $context),
            fn (string $html): string => $this->sanitizer()->sanitize($html),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function interpolateTokens(string $content, array $context): string
    {
        if ($context === []) {
            return $content;
        }

        return preg_replace_callback(
            '/\{\{\s*([A-Za-z]\w*(?:\.[A-Za-z]\w*)*)\s*\}\}/',
            function (array $matches) use ($context): string {
                [$found, $value] = $this->contextValue($context, $matches[1]);

                return $found && (is_scalar($value) || $value === null) ? (string) $value : $matches[0];
            },
            $content,
        ) ?? $content;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: bool, 1: mixed}
     */
    private function contextValue(array $context, string $path): array
    {
        $value = $context;

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            return [false, null];
        }

        return [true, $value];
    }

    private function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(-1);

        foreach (config('capell-frontend.html_content_allowed_attributes', ['class']) as $attribute) {
            if (! is_string($attribute)) {
                continue;
            }

            if ($attribute === '') {
                continue;
            }

            $config = $config->allowAttribute($attribute, '*');
        }

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
