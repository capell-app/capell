<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('capell-installer::installer.heading') }}
        </x-slot>

        <x-slot name="description">
            {{ __('capell-installer::installer.subheading') }}
        </x-slot>

        <form
            method="POST"
            action="{{ route('capell-installer.store') }}"
        >
            @csrf

            <input
                type="hidden"
                name="admin_user_mode"
                value="existing"
            />
            <input
                type="hidden"
                name="existing_user_id"
                value="{{ auth()->id() }}"
            />
            <input
                type="hidden"
                name="admin_panel_changes_mode"
                value="auto"
            />
            <input
                type="hidden"
                name="install_filament_panel"
                value="1"
            />
            <input
                type="hidden"
                name="seed_default_data"
                value="1"
            />
            <input
                type="hidden"
                name="run_as_job"
                value="1"
            />

            @foreach ($defaultPackageNames as $packageName)
                <input
                    type="hidden"
                    name="packages[]"
                    value="{{ $packageName }}"
                />
            @endforeach

            <x-filament::fieldset>
                <x-slot name="label">
                    {{ __('capell-installer::installer.section_setup') }}
                </x-slot>

                <x-filament::input.wrapper>
                    <x-filament::input
                        type="url"
                        name="site_url"
                        :value="old('site_url', config('app.url'))"
                        required
                    />
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-filament::input.select
                        name="language"
                        required
                    >
                        <option
                            value="en"
                            @selected(old('language', config('app.locale', 'en')) === 'en')
                        >
                            English
                        </option>
                        <option
                            value="fr"
                            @selected(old('language', config('app.locale', 'en')) === 'fr')
                        >
                            Français
                        </option>
                        <option
                            value="de"
                            @selected(old('language', config('app.locale', 'en')) === 'de')
                        >
                            Deutsch
                        </option>
                        <option
                            value="es"
                            @selected(old('language', config('app.locale', 'en')) === 'es')
                        >
                            Español
                        </option>
                        <option
                            value="nl"
                            @selected(old('language', config('app.locale', 'en')) === 'nl')
                        >
                            Nederlands
                        </option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-filament::fieldset>

            <x-filament::fieldset>
                <x-slot name="label">
                    {{ __('capell-installer::installer.section_packages') }}
                </x-slot>

                @foreach ($packages as $package)
                    <label>
                        <x-filament::input.checkbox
                            name="packages[]"
                            value="{{ $package['name'] }}"
                            :checked="old('packages') ? in_array($package['name'], old('packages', []), true) : $package['required']"
                            :disabled="$package['required']"
                        />
                        {{ $package['label'] }}
                    </label>
                @endforeach
            </x-filament::fieldset>

            <x-filament::button
                type="submit"
                color="primary"
                size="xl"
            >
                {{ __('capell-installer::widgets.install_action') }}
            </x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
