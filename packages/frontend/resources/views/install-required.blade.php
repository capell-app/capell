<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        />
        <meta
            name="robots"
            content="noindex, nofollow"
        />
        <title>Capell install required</title>
        <style>
            body {
                font-family:
                    system-ui,
                    -apple-system,
                    sans-serif;
                margin: 0;
                padding: 3rem 1rem;
                background: #f7f7f8;
                color: #1a1a1a;
            }
            main {
                max-width: 42rem;
                margin: 0 auto;
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 0.5rem;
                padding: 2rem;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }
            h1 {
                font-size: 1.5rem;
                margin: 0 0 0.75rem;
            }
            p {
                line-height: 1.55;
                margin: 0 0 1rem;
            }
            code {
                background: #f3f4f6;
                padding: 0.15rem 0.4rem;
                border-radius: 0.25rem;
                font-size: 0.95em;
            }
            pre {
                background: #0f172a;
                color: #f1f5f9;
                padding: 1rem;
                border-radius: 0.375rem;
                overflow-x: auto;
                margin: 0 0 1rem;
            }
            a {
                color: #2563eb;
            }
        </style>
    </head>
    <body>
        <main data-testid="capell-install-required">
            <h1>Capell is not installed yet</h1>
            <p>
                This site reached a Capell frontend route, but Capell's core
                tables haven't been created in the database. Run the install
                command from the project root to finish the setup:
            </p>
            <pre><code>php artisan capell:install</code></pre>
            <p>
                If you have already run migrations but the check still fails,
                make sure the
                <code>capell-app/frontend</code>
                package is registered in the
                <code>plugins</code>
                table.
            </p>
            <p>
                Full guide:
                <a
                    href="https://github.com/capell-app/capell/blob/1.x/docs/install-guide.md"
                >
                    docs/install-guide.md
                </a>
            </p>
        </main>
    </body>
</html>
