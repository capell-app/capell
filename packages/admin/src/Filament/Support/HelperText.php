<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Support;

use Capell\Admin\Settings\AdminSettings;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Attach a question-mark helper tooltip to a Filament field.
 *
 * Honours the `admin.show_helper_tooltips` setting: when the editor has
 * turned helpers off, the tooltip is silently skipped so the form stays clean.
 *
 * Translation keys are expected to follow the convention `form.<field>.helper`
 * (nested) or `form.<field>_helper` (legacy flat) — the caller passes the full
 * key, this class does not assume structure.
 *
 * Usage:
 *   TextInput::make('url')
 *       ->label(__('capell-admin::form.page_url.label'))
 *       ->tap(HelperText::from('capell-admin::form.page_url.helper'));
 */
final class HelperText
{
    /**
     * Returns a closure suitable for Filament's `->tap()` that applies a helper tooltip
     * when the admin setting is enabled and the translation key resolves.
     */
    public static function from(string $translationKey): Closure
    {
        return static function (Component $component) use ($translationKey): void {
            self::apply($component, $translationKey);
        };
    }

    /**
     * Apply a helper tooltip directly after the component label.
     *
     * @template T of object
     *
     * @param  T  $component
     * @return T
     */
    public static function apply(object $component, string|Closure|null $tooltip, bool $translate = true): object
    {
        if (! self::enabled()) {
            return $component;
        }

        if ($translate && is_string($tooltip)) {
            $translation = __($tooltip);
            if ($translation === $tooltip) {
                // Key not defined — skip rather than displaying the raw key.
                return $component;
            }

            $tooltip = $translation;
        }

        if ($component instanceof Field || $component instanceof Entry) {
            $component->afterLabel(
                static function (Field|Entry $component) use ($tooltip): ?Schema {
                    $evaluatedTooltip = $tooltip instanceof Closure
                        ? $component->evaluate($tooltip)
                        : $tooltip;

                    if (blank($evaluatedTooltip)) {
                        return null;
                    }

                    return Schema::start([
                        Icon::make(Heroicon::QuestionMarkCircle)
                            ->tooltip($evaluatedTooltip),
                    ]);
                },
            );

            return $component;
        }

        return $component;
    }

    public static function enabled(): bool
    {
        try {
            return resolve(AdminSettings::class)->show_helper_tooltips;
        } catch (Throwable) {
            return true;
        }
    }
}
