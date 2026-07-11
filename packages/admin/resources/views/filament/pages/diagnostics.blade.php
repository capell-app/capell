<x-filament-panels::page>
    <div class="space-y-6">
        <section>
            <h2 class="text-lg font-semibold">
                {{ __('capell-admin::generic.diagnostics') }}
            </h2>
            <dl class="mt-3 grid gap-3 md:grid-cols-2">
                @foreach ($this->safety() as $key => $value)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <dt class="text-sm font-medium">{{ $key }}</dt>
                        <dd class="text-sm text-gray-600">
                            @if (is_array($value))
                                {{ implode(', ', $value) }}
                            @else
                                {{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section>
            <h2 class="text-lg font-semibold">Makers</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($this->makers() as $maker)
                    <li class="rounded-lg border border-gray-200 p-3">
                        <span class="font-medium">{{ $maker->key }}</span>
                        <span class="text-gray-600">
                            {{ $maker->description }}
                        </span>
                        <div class="mt-3">
                            {{ $this->getAction('maker_' . str_replace('.', '_', $maker->key)) }}
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">Registry</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($this->configurators()->merge($this->components())->merge($this->widgets()) as $source)
                    <li class="rounded-lg border border-gray-200 p-3">
                        <span class="font-medium">
                            {{ $source->kind }}: {{ $source->key }}
                        </span>
                        <span class="block text-sm text-gray-600">
                            {{ $source->path ?? $source->class ?? $source->view }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
