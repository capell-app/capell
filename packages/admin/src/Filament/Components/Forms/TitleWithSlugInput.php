<?php

declare(strict_types=1);

/**
 * @author [Filament Title with Slug](https://github.com/camya/filament-title-with-slug)
 */

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Support\Slug\SlugGenerator;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class TitleWithSlugInput
{
    /**
     * @param  array<string, mixed>|Closure|null  $titleExtraInputAttributes
     * @param  array<int, mixed>  $titleRules
     * @param  array<string, mixed>  $titleRuleUniqueParameters
     * @param  array<int, mixed>  $slugRules
     * @param  array<string, mixed>  $slugRuleUniqueParameters
     */
    public static function make(
        // Model fields
        ?string $fieldTitle = null,
        ?string $fieldSlug = null,

        // Url
        string|Closure|null $urlPath = '/',
        string|Closure|null $urlHost = null,
        bool $urlHostVisible = true,
        Closure|string|null $urlVisitLinkLabel = null,
        ?Closure $urlVisitLinkRoute = null,

        // Title
        string|Closure|null $titleLabel = null,
        ?string $titlePlaceholder = null,
        array|Closure|null $titleExtraInputAttributes = null,
        array $titleRules = [
            'required',
        ],
        array $titleRuleUniqueParameters = [],
        bool|Closure $titleIsReadonly = false,
        bool|Closure $titleAutofocus = true,
        ?Closure $titleAfterStateUpdated = null,
        ?Closure $titleFieldWrapper = null,

        // Slug
        ?string $slugLabel = null,
        array $slugRules = [
            'required',
        ],
        ?string $slugStatePath = null,
        array $slugRuleUniqueParameters = [],
        bool|Closure $slugIsReadonly = false,
        ?Closure $slugAfterStateUpdated = null,
        ?Closure $slugSlugifier = null,
        string|Closure $slugRuleRegex = '/^[a-z0-9\-\_]*$/',
        string|Closure|null $slugLabelPostfix = null,
    ): FusedGroup {
        $fieldTitle ??= 'title';
        $fieldSlug ??= 'slug';
        $slugStatePath ??= 'slug';
        $urlHost ??= config('app.url');

        /** Input: "Title" */
        $textInput = TextInput::make($fieldTitle)
            ->disabled($titleIsReadonly)
            ->autofocus($titleAutofocus)
            ->autocomplete(false)
            ->rules($titleRules)
            ->extraInputAttributes($titleExtraInputAttributes ?? ['class' => 'text-xl font-semibold'])
            ->beforeStateDehydrated(fn (TextInput $component, ?string $state): TextInput => $component->state(trim((string) $state)))
            ->placeholder($titlePlaceholder !== '' ? $titlePlaceholder : fn (): Stringable => Str::of($fieldTitle)->headline())
            ->afterStateUpdated(
                function (
                    ?string $state,
                    Set $set,
                    Get $get,
                    string $context,
                    ?Model $record,
                    TextInput $component,
                ) use (
                    $slugSlugifier,
                    $slugStatePath,
                    $titleAfterStateUpdated,
                ): void {
                    $slugAutoUpdateDisabled = $get('slug_auto_update_disabled');

                    if ($context === 'edit' && filled($record)) {
                        $slugAutoUpdateDisabled = true;
                    }

                    if (! (bool) $slugAutoUpdateDisabled && filled($state)) {
                        $set($slugStatePath, self::slugify($slugSlugifier, $state));
                    }

                    if ($titleAfterStateUpdated instanceof Closure) {
                        $component->evaluate($titleAfterStateUpdated);
                    }
                },
            )
            ->afterStateUpdatedJs(
                function () use ($slugStatePath): string {
                    $setSlugState = SlugGenerator::slugifyState("\$state ?? ''", $slugStatePath);

                    return <<<JS
                        const slugAutoUpdateDisabled = \$get('slug_auto_update_disabled');
                        if (! slugAutoUpdateDisabled && \$state) {
                            {$setSlugState}
                        }
                    JS;
                },
            );

        if (in_array('required', $titleRules, true)) {
            $textInput->required();
        }

        if ($titleLabel === null) {
            $textInput->hiddenLabel();
        }

        if ($titleLabel !== null) {
            $textInput->label($titleLabel);
        }

        if ($titleRuleUniqueParameters !== []) {
            $textInput->unique(...$titleRuleUniqueParameters);
        }

        /** Input: "Slug" (+ view) */
        $slugInput = SlugInput::make($fieldSlug)

            // Custom SlugInput methods
            ->slugInputVisitLinkRoute($urlVisitLinkRoute)
            ->slugInputVisitLinkLabel($urlVisitLinkLabel)
            ->slugInputContext(fn (string $context): string => in_array($context, ['edit', 'editOption'], true) ? 'edit' : 'create')
            ->slugInputRecordSlug(fn (?Model $record): ?string => $record?->getAttribute($fieldSlug))
            ->slugInputModelName(
                fn (?Model $record) => $record instanceof Model
                    ? Str::of(class_basename($record))->headline()
                    : '',
            )
            ->slugInputLabelPrefix($slugLabel)
            ->slugInputBasePath($urlPath)
            ->slugInputBaseUrl($urlHost)
            ->slugInputShowUrl($urlHostVisible)
            ->slugInputSlugLabelPostfix($slugLabelPostfix)

            // Default TextInput methods
            ->readOnly($slugIsReadonly)
            ->autocomplete(false)
            ->hiddenLabel()
            ->regex($slugRuleRegex)
            ->rules($slugRules)
            ->statePath($slugStatePath)
            ->afterStateUpdated(
                function (SlugInput $component, Set $set, mixed $state, ?Model $record, string $operation) use ($slugAfterStateUpdated): void {
                    if ($record instanceof Model && in_array($operation, ['edit', 'editOption'], true)) {
                        return;
                    }

                    if ($slugAfterStateUpdated instanceof Closure) {
                        $component->evaluate($slugAfterStateUpdated);
                    }

                    $set('slug_auto_update_disabled', $state !== null && trim($state) !== '');
                },
            );

        if (in_array('required', $slugRules, true)) {
            $slugInput->required();
        }

        $slugRuleUniqueParameters !== []
            ? $slugInput->unique(...$slugRuleUniqueParameters)
            : $slugInput->unique(ignorable: fn (?Model $record): ?Model => $record);

        /** Input: "Slug Auto Update Disabled" (Hidden) */
        $hiddenInputSlugAutoUpdateDisabled = Hidden::make('slug_auto_update_disabled')
            ->dehydrated(false)
            ->afterStateHydrated(
                fn (Hidden $component, string $operation, ?Model $record): Hidden => $component->state(
                    $operation === 'edit' && filled($record),
                ),
            );

        // Wrap title field into wrapping closure, if set
        if ($titleFieldWrapper instanceof Closure) {
            $textInput = $titleFieldWrapper($textInput);
        }

        return FusedGroup::make()
            ->schema([
                $textInput,
                $slugInput,
                $hiddenInputSlugAutoUpdateDisabled,
            ]);
    }

    /** Fallback slugifier, over-writable with slugSlugifier parameter. */
    protected static function slugify(?Closure $slugifier, ?string $text): string
    {
        if (is_null($text) || trim($text) === '') {
            return '';
        }

        return is_callable($slugifier)
            ? $slugifier($text)
            : Str::slug($text);
    }
}
