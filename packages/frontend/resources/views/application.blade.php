@php
    use Capell\Frontend\Data\ApplicationPageData;

    $applicationPage = request()->attributes->get(ApplicationPageData::ATTRIBUTE);

    throw_unless(
        $applicationPage instanceof ApplicationPageData,
        LogicException::class,
        'The capell::application layout needs the frontend.application:{area} middleware on its route.',
    );
@endphp

@include($applicationPage->layoutFile, [
    'applicationPage' => $applicationPage,
    'componentName' => 'capell.frontend.application',
    'language' => $applicationPage->language,
    'layout' => $applicationPage->layout,
    'livewireEnabled' => $livewireEnabled ?? true,
    'params' => [],
    'pageRecord' => $applicationPage->page,
    'site' => $applicationPage->site,
    'slot' => $slot,
    'theme' => $applicationPage->theme,
])
