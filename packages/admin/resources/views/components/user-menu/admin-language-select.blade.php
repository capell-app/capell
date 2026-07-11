@php
    use Capell\Admin\Actions\Users\ListAdminLanguageOptionsAction;
    use Capell\Admin\Actions\Users\ResolvePreferredAdminLanguageIdAction;
    use Capell\Core\Support\Database\RuntimeSchemaState;
    use Illuminate\Database\Eloquent\Model;

    $schema = resolve(RuntimeSchemaState::class);
    $user = request()->user() ?? auth()->user();
    $canSetAdminLanguage = ! request()->routeIs('filament.*.auth.*')
        && $schema->hasTable('users')
        && $schema->hasColumn('users', 'preferred_admin_language_id');
    $languageOptions = $canSetAdminLanguage ? ListAdminLanguageOptionsAction::run() : collect();
    $selectedLanguageId = $canSetAdminLanguage
        ? ResolvePreferredAdminLanguageIdAction::run($user instanceof Model ? $user : null)
        : null;
@endphp

@if ($languageOptions->isNotEmpty())
    <form
        method="POST"
        action="{{ route('capell-admin.profile.language.update') }}"
        class="fi-dropdown-list border-t border-gray-950/5 p-2 dark:border-white/10"
        data-capell-admin-language-select-panel="true"
    >
        @csrf

        <div
            class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex flex-col items-stretch gap-1.5 rounded-md p-2"
        >
            <label
                for="capell-admin-user-menu-language"
                class="text-xs font-medium text-gray-500 dark:text-gray-400"
            >
                {{ __('capell-admin::form.preferred_admin_language') }}
            </label>

            <select
                id="capell-admin-user-menu-language"
                name="preferred_admin_language_id"
                class="focus:border-primary-500 focus:ring-primary-500 block w-full rounded-md border-gray-200 bg-white text-sm text-gray-950 transition duration-75 outline-none focus:ring-1 dark:border-white/10 dark:bg-gray-950 dark:text-white"
                onchange="this.form.submit()"
            >
                <option value="">
                    {{ __('capell-admin::form.default_locale') }}
                </option>

                @foreach ($languageOptions as $languageId => $languageName)
                    <option
                        value="{{ $languageId }}"
                        @selected($selectedLanguageId === $languageId)
                    >
                        {{ $languageName }}
                    </option>
                @endforeach
            </select>
        </div>
    </form>
@endif
