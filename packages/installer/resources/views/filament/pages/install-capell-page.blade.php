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

                <div class="grid gap-y-2">
                    <label
                        for="install-site-name"
                        class="text-sm font-medium leading-6 text-gray-950 dark:text-white"
                    >
                        {{ __('capell-installer::installer.field_site_name') }}
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="install-site-name"
                            type="text"
                            name="site_name"
                            maxlength="255"
                            aria-describedby="install-site-name-help"
                            :value="old('site_name', \Capell\Core\Support\Install\InstallSiteName::fallback())"
                        />
                    </x-filament::input.wrapper>
                    <p
                        id="install-site-name-help"
                        class="text-sm text-gray-500 dark:text-gray-400"
                    >
                        {{ __('capell-core::install.site.name_hint') }}
                    </p>
                </div>

                <div class="grid gap-y-2">
                    <label
                        for="install-site-url"
                        class="text-sm font-medium leading-6 text-gray-950 dark:text-white"
                    >
                        {{ __('capell-installer::installer.field_site_url') }}
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="install-site-url"
                            type="url"
                            name="site_url"
                            aria-describedby="install-site-url-help"
                            :value="old('site_url', config('app.url'))"
                            required
                        />
                    </x-filament::input.wrapper>
                    <p
                        id="install-site-url-help"
                        class="text-sm text-gray-500 dark:text-gray-400"
                    >
                        {{ __('capell-core::install.site.url_hint') }}
                    </p>
                </div>

                <div class="grid gap-y-2">
                    <label
                        for="install-language"
                        class="text-sm font-medium leading-6 text-gray-950 dark:text-white"
                    >
                        {{ __('capell-installer::installer.field_language') }}
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="install-language"
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
                </div>
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
