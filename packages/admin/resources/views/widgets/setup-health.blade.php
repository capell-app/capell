@php
    use Capell\Admin\Enums\SetupHealthEnum;

    $totalChecks = $this->data->checks->count();
    $greenCount = 0;
    $hasRed = false;

    foreach ($this->data->checks as $check) {
        if ($check->status === SetupHealthEnum::Green) {
            $greenCount++;
        }
        if ($check->status === SetupHealthEnum::Red) {
            $hasRed = true;
        }
    }

    $progressPercentage = $totalChecks > 0 ? (int) (($greenCount / $totalChecks) * 100) : 0;
    $failingChecks = [];

    foreach ($this->data->checks as $check) {
        if ($check->status !== SetupHealthEnum::Green) {
            $failingChecks[] = $check;
        }
    }
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Setup health">
        <div class="space-y-4">
            <!-- Progress Bar -->
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                    >
                        {{ $greenCount }} of {{ $totalChecks }} complete
                    </span>
                    <span
                        class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                    >
                        {{ $progressPercentage }}%
                    </span>
                </div>
                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                >
                    <div
                        class="h-full rounded-full transition-all duration-300 ease-out"
                        style="
                            width: {{ $progressPercentage }}%;
                            background-color: {{ $progressPercentage === 100 ? '#10b981' : ($hasRed ? '#ef4444' : '#f59e0b') }};
                        "
                    ></div>
                </div>
            </div>

            <!-- Failing Items with Actions -->
            @if (count($failingChecks) > 0)
                <div class="border-t border-gray-200 pt-4 dark:border-gray-700">
                    <p
                        class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300"
                    >
                        Items to complete:
                    </p>
                    <div class="space-y-2">
                        @foreach ($failingChecks as $check)
                            <div
                                class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800"
                            >
                                <div class="flex items-center gap-3">
                                    @if ($check->status === SetupHealthEnum::Amber)
                                        <svg
                                            class="h-5 w-5 flex-shrink-0 text-amber-500"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    @else
                                        <svg
                                            class="h-5 w-5 flex-shrink-0 text-red-500"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M13.477 14.89A6 6 0 0 1 5.11 6.524a6 6 0 0 1 8.367 8.366m-1.414-1.414A4 4 0 0 0 6.938 5.937m7.07 7.07a4 4 0 0 0-5.656-5.656m1.414 1.414A2 2 0 1 0 8.95 8.95m7.07 7.07L1.414 1.414M19 19L1 1"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    @endif
                                    <span
                                        class="text-sm text-gray-700 dark:text-gray-300"
                                    >
                                        {{ $check->label }}
                                    </span>
                                </div>
                                @if ($check->fixUrl && $check->fixLabel)
                                    <a
                                        href="{{ $check->fixUrl }}"
                                        class="bg-primary-600 hover:bg-primary-700 inline-flex items-center gap-2 rounded px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-white"
                                    >
                                        {{ $check->fixLabel }}
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="rounded-lg bg-green-50 p-3 dark:bg-green-950">
                    <p
                        class="flex items-center gap-2 text-sm font-medium text-green-700 dark:text-green-300"
                    >
                        <svg
                            class="h-5 w-5 flex-shrink-0"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm3.707-9.293a1 1 0 0 0-1.414-1.414L9 10.586 7.707 9.293a1 1 0 0 0-1.414 1.414l2 2a1 1 0 0 0 1.414 0l4-4z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        All setup requirements met!
                    </p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
