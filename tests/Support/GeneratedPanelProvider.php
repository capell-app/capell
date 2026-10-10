<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Filament\Panel;
use Filament\PanelProvider;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

final class GeneratedPanelProvider
{
    public static function load(string $path): Panel
    {
        $source = file_get_contents($path);
        throw_unless(is_string($source), RuntimeException::class, 'Unable to read generated panel provider.');

        $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $namespace = 'Capell\\Tests\\Generated\\Panel' . bin2hex(random_bytes(8));
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                // Isolate declarations so multiple generated providers can execute in one worker.
                $statement->name = new Name($namespace);
            }
        }

        eval((new Standard)->prettyPrint($statements));
        $providerClass = $namespace . '\\AdminPanelProvider';
        throw_unless(is_subclass_of($providerClass, PanelProvider::class), RuntimeException::class, 'Generated provider must configure a Filament panel.');

        return new $providerClass(app())->panel(Panel::make());
    }
}
