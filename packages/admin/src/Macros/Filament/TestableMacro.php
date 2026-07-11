<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;
use Illuminate\Testing\Assert;
use Livewire\Features\SupportTesting\Testable;

/**
 * @method HasForms|HasTable instance()
 * @method static assertSchemaExists(string $schema = 'form')
 *
 * @mixin Testable
 */
class TestableMacro
{
    /**
     * @return Closure(array<int|string, mixed> $keys, string $formName): static
     *
     * @return-closure-this Testable
     */
    public function assertHasAllFormErrors(): Closure
    {
        return function (array $keys = [], string $formName = 'form'): static {
            $this->assertSchemaExists($formName);

            $livewire = $this->instance();

            /** @var Schema $schema */
            /** @phpstan-ignore-next-line */
            $schema = $livewire->getPropertyValue($formName);

            /** @phpstan-ignore-next-line */
            $formStatePath = $schema->getStatePath();

            /** @var Collection<int|string, mixed> $errorKeys */
            $errorKeys = collect($keys);

            $errorKeys
                ->mapWithKeys(function (mixed $value, int|string $key) use ($formStatePath): array {
                    if (is_int($key)) {
                        return [$key => (filled($formStatePath) ? sprintf('%s.%s', $formStatePath, $value) : $value)];
                    }

                    return [(filled($formStatePath) ? sprintf('%s.%s', $formStatePath, $key) : $key) => $value];
                })
                ->each(function (mixed $value, int|string $key) use ($livewire): void {
                    if (is_string($key) && str_contains($key, '*')) {
                        $originalKey = $key;

                        /** @phpstan-ignore-next-line */
                        $errors = $livewire->getErrorBag();

                        /** @var list<string> $validationErrorKeys */
                        $validationErrorKeys = $errors->keys();

                        $errorBagKeys = collect($validationErrorKeys);

                        $key = $errorBagKeys
                            ->firstWhere(
                                fn (string $errorKey): int|false => preg_match('/^' . str_replace('\\*', '.*', preg_quote($key, '/')) . '$/', $errorKey),
                            );

                        Assert::assertNotNull(
                            $key,
                            sprintf('Failed asserting that the form has an error for key [%s].', $originalKey),
                        );
                    }

                    $this->assertHasErrors([$key => $value]);
                });

            Assert::assertCount(
                count($keys),
                /** @phpstan-ignore-next-line */
                $livewire->getErrorBag(),
                'Failed asserting that the form has all the errors.',
            );

            return $this;
        };
    }

    /**
     * @return Closure(): static
     *
     * @return-closure-this Testable
     */
    public function assertRecordCreated(): Closure
    {
        return function (): static {
            $record = $this->get('record');

            Assert::assertNotNull($record);

            $this->assertSet('record', $record);

            return $this;
        };
    }

    /**
     * @return Closure(bool $show): static
     *
     * @return-closure-this Testable
     */
    public function toggleAllTableColumns(): Closure
    {
        return function (bool $show = true): static {
            $tableColumns = (array) $this->get('tableColumns');

            foreach ($tableColumns as &$column) {
                if (! ($column['isToggleable'] ?? false)) {
                    continue;
                }

                $column['isToggled'] = $show;
            }

            $this->set('tableColumns', $tableColumns);

            $this->call('applyTableColumnManager');

            return $this;
        };
    }
}
