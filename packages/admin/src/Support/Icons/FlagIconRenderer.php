<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Icons;

use Capell\Admin\Contracts\Support\FlagIconRenderer as FlagIconRendererContract;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;

class FlagIconRenderer implements FlagIconRendererContract
{
    /**
     * @var array<string, string>
     */
    private const array SUBDIVISION_FLAGS = [
        'gb-eng' => "\u{1F3F4}",
        'gb-sct' => "\u{1F3F4}",
        'gb-wls' => "\u{1F3F4}",
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function render(?string $flag, ?string $label = null, string $style = '4x3', array $attributes = []): HtmlString
    {
        $flagLabel = $this->flagLabel($flag);
        $attributeBag = new ComponentAttributeBag($attributes);

        if ($flagLabel === '') {
            return new HtmlString('');
        }

        return new HtmlString(sprintf(
            '<span %s role="img" aria-label="%s">%s</span>',
            $attributeBag->class(['inline-flex shrink-0 items-center justify-center text-base leading-none']),
            e($this->fallbackLabel($flag, $label)),
            e($flagLabel),
        ));
    }

    public function fallbackLabel(?string $flag, ?string $label = null): string
    {
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        if (! is_string($flag) || trim($flag) === '') {
            return '';
        }

        return strtoupper(trim(preg_replace('/\A(?:flag-)?(?:1x1|4x3)-/', '', strtolower($flag)) ?? ''));
    }

    private function flagLabel(?string $flag): string
    {
        $code = strtolower($this->normaliseFlagCode($flag));

        if ($code === '') {
            return '';
        }

        if (isset(self::SUBDIVISION_FLAGS[$code])) {
            return self::SUBDIVISION_FLAGS[$code];
        }

        $countryCode = strtoupper($code);

        if (! preg_match('/\A[A-Z]{2}\z/', $countryCode)) {
            return strtoupper($code);
        }

        return mb_chr(0x1F1E6 + ord($countryCode[0]) - ord('A'))
            . mb_chr(0x1F1E6 + ord($countryCode[1]) - ord('A'));
    }

    private function normaliseFlagCode(?string $flag): string
    {
        if (! is_string($flag) || trim($flag) === '') {
            return '';
        }

        return trim(preg_replace('/\A(?:flag-)?(?:1x1|4x3)-/', '', strtolower($flag)) ?? '');
    }
}
