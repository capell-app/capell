<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Settings;

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Support\Settings\RegisteredSettingsClasses;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use InvalidArgumentException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;

/**
 * Writes one registered Capell setting from text, converting it to the property's declared type
 * and refusing a value of the wrong type rather than storing it.
 *
 * @method static mixed run(string $group, string $property, string $value)
 */
final class WriteSettingAction
{
    use AsFake;
    use AsObject;

    public function handle(string $group, string $property, string $value): mixed
    {
        $class = RegisteredSettingsClasses::byGroup()[$group] ?? null;

        throw_if(
            $class === null,
            InvalidArgumentException::class,
            sprintf('No settings group is called [%s]. Groups: %s.', $group, implode(', ', array_keys(RegisteredSettingsClasses::byGroup())) ?: 'none'),
        );

        throw_unless(
            in_array($property, ReadSettingsAction::properties($class), true),
            InvalidArgumentException::class,
            sprintf('The %s settings have no [%s] setting. Settings: %s.', $group, $property, implode(', ', ReadSettingsAction::properties($class))),
        );

        $hidden = in_array($property, ReadSettingsAction::encrypted($class), true);

        /** @var Settings $settings */
        $settings = resolve($class);

        throw_if(
            $settings->isLocked($property),
            InvalidArgumentException::class,
            sprintf('%s.%s is locked and cannot be changed here. Unlock it first.', $group, $property),
        );

        $converted = $this->convert(new ReflectionProperty($class, $property), $value, $group . '.' . $property, $hidden);

        if ($settings instanceof ThemeStudioSettings && $property === 'brandProfile' && is_array($converted)) {
            // The admin save merges onto the stored profile, which keeps every token a string.
            $converted = BrandProfileData::from($settings->brandProfile)->merge($converted)->toArray();
        }

        $settings->{$property} = $converted;
        $settings->save();

        if ($settings instanceof ThemeStudioSettings) {
            // The admin save path does the same, so public pages pick up the new theme settings.
            event(new FrontendSurrogateKeysInvalidated(array_values(Site::query()->pluck('id')->map(fn (mixed $id): string => 'site-' . $id)->all())));
        }

        return $hidden ? ReadSettingsAction::HIDDEN : $converted;
    }

    private function convert(ReflectionProperty $property, string $value, string $name, bool $hidden): mixed
    {
        $shown = $hidden ? ReadSettingsAction::HIDDEN : $value;

        $type = $property->getType();

        if ($type?->allowsNull() === true && strtolower($value) === 'null') {
            return null;
        }

        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

        return match ($typeName) {
            'string' => $value,
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $this->refuse($name, 'a whole number', $shown),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? $this->refuse($name, 'a number', $shown),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $this->refuse($name, 'true or false', $shown),
            'array' => $this->decodeArray($name, $value, $shown),
            default => $this->refuse($name, 'a value this command can set; change it in the admin panel under Settings', $shown),
        };
    }

    /**
     * @return array<mixed>
     */
    private function decodeArray(string $name, string $value, string $shown): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        return is_array($decoded) ? $decoded : $this->refuse($name, 'a JSON array, for example ["a","b"]', $shown);
    }

    private function refuse(string $name, string $expected, string $value): never
    {
        throw new InvalidArgumentException(sprintf('%s must be %s, not [%s].', $name, $expected, $value));
    }
}
