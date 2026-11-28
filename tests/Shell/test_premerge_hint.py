import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


spec = importlib.util.spec_from_file_location('premerge_hint', Path(__file__).resolve().parents[2] / 'scripts/premerge-hint.py')
hint = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hint)


def checkout(root: Path, files: dict[str, str]) -> Path:
    for path, source in files.items():
        (root / path).parent.mkdir(parents=True, exist_ok=True)
        (root / path).write_text(source)
    subprocess.run(('git', 'init', '-q', str(root)), check=True)
    subprocess.run(('git', '-C', str(root), 'add', '.'), check=True)
    subprocess.run(('git', '-C', str(root), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                    '-c', 'commit.gpgsign=false', 'commit', '-q', '--no-verify', '-m', 'Fixture'), check=True)
    return root


def php(namespace: str, body: str) -> str:
    return f"<?php\n\ndeclare(strict_types=1);\n\nnamespace {namespace};\n\n{body}\n"


CORE = {
    'composer.json': json.dumps({'autoload': {'psr-4': {'Capell\\Core\\': 'packages/core/src'}}}),
    'packages/core/src/Actions/BuildSite.php': php('Capell\\Core\\Actions', 'use Capell\\Core\\Support\\SharedRowGuard;\n\nfinal class BuildSite\n{\n}'),
    'packages/core/src/Support/SharedRowGuard.php': php('Capell\\Core\\Support', 'final class SharedRowGuard\n{\n}'),
}

PACKAGES = {
    # The AI Creator shape: a wrapper imports the Core action under an alias, and the
    # apply action in the same namespace calls the wrapper with no import at all.
    'packages/ai-creator/src/Actions/BuildSite.php': php(
        'Capell\\AiCreator\\Actions',
        'use Capell\\Core\\Actions\\BuildSite as CoreBuildSite;\n\nfinal class BuildSite\n{\n    public function handle(): void\n    {\n        CoreBuildSite::run();\n    }\n}',
    ),
    'packages/ai-creator/src/Actions/ApplySession.php': php(
        'Capell\\AiCreator\\Actions',
        'final class ApplySession\n{\n    public function handle(): void\n    {\n        BuildSite::run();\n    }\n}',
    ),
    'packages/ai-creator/src/Providers/AiCreatorServiceProvider.php': php(
        'Capell\\AiCreator\\Providers',
        'use Capell\\AiCreator\\Actions\\ApplySession;\n\nfinal class AiCreatorServiceProvider\n{\n    public array $actions = [ApplySession::class];\n}',
    ),
    'packages/ai-creator/tests/TestCase.php': php(
        'Capell\\AiCreator\\Tests',
        'use Capell\\AiCreator\\Providers\\AiCreatorServiceProvider;\n\nabstract class TestCase\n{\n    protected array $providers = [AiCreatorServiceProvider::class];\n}',
    ),
    'packages/ai-creator/tests/Feature/ApplySessionTest.php': php(
        'Capell\\AiCreator\\Tests\\Feature',
        "use Capell\\AiCreator\\Actions\\ApplySession;\n\nit('applies a session', fn () => ApplySession::run());",
    ),
    'packages/ai-creator/tests/Feature/SessionListTest.php': php(
        'Capell\\AiCreator\\Tests\\Feature',
        "use Capell\\AiCreator\\Tests\\TestCase;\n\nuses(TestCase::class);\n\nit('lists sessions', fn () => true);",
    ),
}


class PremergeHintTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        root = Path(self.directory.name)
        self.core = checkout(root / 'capell-4', CORE)
        self.packages = checkout(root / 'capell-packages-4', PACKAGES)

    def tearDown(self):
        self.directory.cleanup()

    def hint(self, *paths: str) -> tuple[int, str]:
        output = io.StringIO()
        with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
            status = hint.main(['--core', str(self.core), '--packages', str(self.packages), *paths])
        return status, output.getvalue()

    def test_a_same_namespace_caller_of_a_package_wrapper_lists_its_tests(self):
        status, output = self.hint('packages/core/src/Actions/BuildSite.php')

        self.assertEqual(status, 0, output)
        self.assertIn('Capell\\Core\\Actions\\BuildSite -> ai-creator', output)
        self.assertIn('packages/ai-creator/tests/Feature/ApplySessionTest.php', output)
        # The provider registers the apply action; following it would list every test that boots the package.
        self.assertNotIn('SessionListTest.php', output)

    def test_a_core_helper_reaches_packages_through_the_core_class_that_calls_it(self):
        status, output = self.hint('packages/core/src/Support/SharedRowGuard.php')

        self.assertEqual(status, 0, output)
        self.assertIn('packages/ai-creator/tests/Feature/ApplySessionTest.php', output)

    def test_a_missing_packages_checkout_fails_instead_of_reporting_no_consumers(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
            status = hint.main(['--core', str(self.core), '--packages', str(self.packages / 'absent'), 'packages/core/src/Actions/BuildSite.php'])

        self.assertEqual(status, 2)
        self.assertIn('Packages consumers were not checked', output.getvalue())


if __name__ == '__main__':
    unittest.main()
