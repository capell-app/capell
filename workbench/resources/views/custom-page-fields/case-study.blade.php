@php($caseStudy = $publicRenderData?->extensionData('case-study'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    />
    <title>Northline Studio identity and website</title>
    <style>
        body { margin: 0; background: #f5f3ed; color: #153e35; font-family: system-ui, sans-serif; }
        header { display: flex; justify-content: space-between; padding: 36px 6vw; border-bottom: 1px solid #ced7cd; }
        header strong { font-size: 23px; letter-spacing: -.04em; }
        main { max-width: 1050px; margin: 0 auto; padding: 90px 6vw; }
        h1 { max-width: 850px; margin: 25px 0 40px; font-size: clamp(40px, 5.4vw, 76px); line-height: 1.05; letter-spacing: -.055em; font-weight: 550; }
        .client { margin: 0 0 52px; padding-top: 22px; border-top: 1px solid #b6c9bd; font-size: 20px; }
        .client span { display: block; margin-bottom: 7px; font-size: 12px; text-transform: uppercase; letter-spacing: .1em; }
        .project { background: #dbe7da; padding: 52px; border-radius: 4px; }
        .project h2 { max-width: 680px; font-size: 38px; font-weight: 500; letter-spacing: -.035em; }
        .project p { max-width: 560px; font-size: 19px; line-height: 1.6; }
    </style>
</head>
<body>
    <header>
        <strong>Northline Studio</strong><span>Case study, 2026</span>
    </header>
    <main>
        <h1>Northline Studio identity and website</h1>
        @if ($caseStudy?->clientName)
            <p class="client"><span>Client</span>{{ $caseStudy->clientName }}</p>
        @endif
        <section class="project">
            <h2>Brand identity and website redesign</h2>
            <p>Northline needed an identity and a website that show its portfolio first. The new site puts recent projects on the home page and gives each one its own case study.</p>
        </section>
    </main>
</body>
</html>
