<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Database\Factories\Concerns\HasAdmin;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Database\Factories\Concerns\HasStatus;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    use HasAdmin;
    use HasMeta;
    use HasStatus;

    protected $model = Language::class;

    /**
     * Language codes are unique, and a random generator repeats eventually, so every default
     * language takes the next code from a process-wide sequence. The sequence walks the ISO 639-2
     * private-use range (qaa to qtz) first, which no real language uses, then continues with
     * x-prefixed codes. It never repeats within a process, whatever seed the run uses.
     */
    private static int $codeSequence = 0;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $code = $this->nextCode();

        return [
            'name' => $this->faker->country(),
            'locale' => $code,
            'code' => $code,
            'flag' => str($code)->replace('_', '-')->lower()->toString(),
            'status' => true,
            'default' => fn (): bool => ! Language::query()->default()->exists(),
            'order' => $this->faker->numberBetween(1, 100),
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function default(): static
    {
        return $this->state([
            'code' => 'en',
            'flag' => 'gb-eng',
            'locale' => 'en',
            'order' => 1,
            'default' => true,
        ]);
    }

    public function forCountry(string $name, string $locale, string $code, string $flag, int $order = 1, bool $isDefault = false): static
    {
        return $this->state([
            'name' => $name,
            'locale' => $locale,
            'code' => $code,
            'flag' => $flag,
            'status' => true,
            'default' => $isDefault,
            'order' => $order,
        ]);
    }

    public function english(bool $isDefault = true, int $order = 1): static
    {
        return $this->forCountry('English', 'en', 'en', 'gb-eng', $order, $isDefault);
    }

    public function french(bool $isDefault = false, int $order = 2): static
    {
        return $this->forCountry('Français', 'fr', 'fr', 'fr', $order, $isDefault);
    }

    public function german(bool $isDefault = false, int $order = 3): static
    {
        return $this->forCountry('Deutsch', 'de', 'de', 'de', $order, $isDefault);
    }

    public function spanish(bool $isDefault = false, int $order = 4): static
    {
        return $this->forCountry('Español', 'es', 'es', 'es', $order, $isDefault);
    }

    public function italian(bool $isDefault = false, int $order = 5): static
    {
        return $this->forCountry('Italiano', 'it', 'it', 'it', $order, $isDefault);
    }

    private function nextCode(): string
    {
        $index = self::$codeSequence++;

        if ($index < 26 * 20) {
            return 'q' . chr(ord('a') + intdiv($index, 26)) . chr(ord('a') + $index % 26);
        }

        return 'x' . base_convert((string) $index, 10, 36);
    }
}
