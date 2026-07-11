<?php

declare(strict_types=1);

namespace Capell\Admin\Support\AdminPanelIntegration;

use Capell\Admin\Data\AdminPanelIntegration\AdminPanelChangeResultData;
use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Enums\AdminPanelFailureCategory;
use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Filament\Http\Middleware\Authenticate;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;

final class AdminPanelProviderEditor
{
    private const string DOCS_URL = 'https://capellcms.com/docs/admin-setup';

    private readonly PhpFileEditor $editor;

    private ?Return_ $panelReturn = null;

    public function __construct(private readonly string $panelPath)
    {
        $this->editor = new PhpFileEditor($panelPath);
    }

    /**
     * @param  array<int, array{in: string, for: string}>  $discoverConfigurators
     */
    public function addPlugin(array $discoverConfigurators): AdminPanelChangeResultData
    {
        $hasCapellPlugin = str_contains($this->editor->originalContent(), 'CapellAdminPlugin');

        if ($this->hasMethodCall('plugin') && $hasCapellPlugin) {
            return $this->alreadyApplied('plugin', 'CapellAdminPlugin is already registered.');
        }

        $return = $this->editablePanelReturn();
        if (! $return instanceof Return_) {
            return $this->manual('plugin', 'Add CapellAdminPlugin::make()->discoverConfigurators(...) manually.');
        }

        $this->editor->addUseStatements([CapellAdminPlugin::class]);

        if (! $hasCapellPlugin) {
            $plugin = new StaticCall(new Name('CapellAdminPlugin'), 'make');

            foreach ($discoverConfigurators === [] ? $this->defaultDiscoverConfigurators() : $discoverConfigurators as $configurator) {
                $plugin = new MethodCall($plugin, 'discoverConfigurators', [
                    new Arg(new FuncCall(new Name('app_path'), [new Arg(new String_($configurator['in']))]), false, false, [], new Identifier('in')),
                    new Arg(new String_($configurator['for']), false, false, [], new Identifier('for')),
                ]);
            }

            if (! $this->appendPanelMethodCall($return, 'plugin', [new Arg($plugin)])) {
                return $this->manual('plugin', 'Add CapellAdminPlugin::make()->discoverConfigurators(...) manually.');
            }
        }

        return $this->applied('plugin', 'Added Capell admin plugin.');
    }

    public function addColors(): AdminPanelChangeResultData
    {
        if ($this->hasMethodCall('colors')) {
            return $this->alreadyApplied('colors', 'Panel already defines colors.');
        }

        $return = $this->editablePanelReturn();
        if (! $return instanceof Return_) {
            return $this->manual('colors', 'Add ->colors(FilamentColorEnum::colors()) manually.');
        }

        $this->editor->addUseStatements([FilamentColorEnum::class]);
        if (! $this->appendPanelMethodCall($return, 'colors', [
            new Arg(new StaticCall(new Name('FilamentColorEnum'), 'colors')),
        ])) {
            return $this->manual('colors', 'Add ->colors(FilamentColorEnum::colors()) manually.');
        }

        return $this->applied('colors', 'Added Capell colors.');
    }

    public function addWidgets(): AdminPanelChangeResultData
    {
        if (str_contains($this->editor->originalContent(), 'CapellAdmin::getWidgets()')) {
            return $this->alreadyApplied('widgets', 'Capell widgets are already registered.');
        }

        $return = $this->editablePanelReturn();
        if (! $return instanceof Return_) {
            return $this->manual('widgets', 'Add ->widgets([...CapellAdmin::getWidgets()]) manually.');
        }

        $this->editor->addUseStatements([CapellAdmin::class]);
        $existingWidgets = $this->findMethodCall('widgets');
        $spreadWidgets = new ArrayItem(new StaticCall(new Name('CapellAdmin'), 'getWidgets'), null, false, [], true);

        $existingWidgetsArray = $existingWidgets instanceof MethodCall ? $this->firstArrayArgument($existingWidgets) : null;

        if ($existingWidgetsArray instanceof Array_) {
            $existingWidgetsArray->items[] = $spreadWidgets;

            return $this->applied('widgets', 'Added Capell widgets to existing widgets array.');
        }

        if ($existingWidgets instanceof MethodCall) {
            return $this->manual('widgets', 'Add ...CapellAdmin::getWidgets() to the existing widgets configuration.');
        }

        if (! $this->appendPanelMethodCall($return, 'widgets', [
            new Arg(new Array_([$spreadWidgets])),
        ])) {
            return $this->manual('widgets', 'Add ->widgets([...CapellAdmin::getWidgets()]) manually.');
        }

        return $this->applied('widgets', 'Added Capell widgets.');
    }

    public function addDashboardPage(): AdminPanelChangeResultData
    {
        if (str_contains($this->editor->originalContent(), 'CapellDashboard::class')) {
            return $this->alreadyApplied('dashboard', 'Capell dashboard is already registered.');
        }

        $return = $this->editablePanelReturn();
        if (! $return instanceof Return_) {
            return $this->manual('dashboard', 'Add CapellDashboard::class to the panel pages manually.');
        }

        $this->editor->addUseStatements([CapellDashboard::class]);
        $existingPages = $this->findMethodCall('pages');
        $dashboardPage = new ArrayItem(new ClassConstFetch(new Name('CapellDashboard'), 'class'));

        $existingPagesArray = $existingPages instanceof MethodCall ? $this->firstArrayArgument($existingPages) : null;

        if ($existingPagesArray instanceof Array_) {
            $existingPagesArray->items = array_values(array_filter(
                $existingPagesArray->items,
                fn (?ArrayItem $item): bool => ! $this->isDefaultFilamentDashboardPage($item),
            ));
            $existingPagesArray->items[] = $dashboardPage;

            return $this->applied('dashboard', 'Added Capell dashboard to existing pages array.');
        }

        if ($existingPages instanceof MethodCall) {
            return $this->manual('dashboard', 'Add CapellDashboard::class to the existing pages configuration.');
        }

        if (! $this->appendPanelMethodCall($return, 'pages', [
            new Arg(new Array_([$dashboardPage])),
        ])) {
            return $this->manual('dashboard', 'Add CapellDashboard::class to the panel pages manually.');
        }

        return $this->applied('dashboard', 'Added Capell dashboard.');
    }

    public function addNavigation(): AdminPanelChangeResultData
    {
        $hasItems = str_contains($this->editor->originalContent(), 'CapellAdmin::getNavigationItems()');
        $hasGroups = str_contains($this->editor->originalContent(), 'CapellAdmin::getNavigationGroups()');

        if ($hasItems && $hasGroups) {
            return $this->alreadyApplied('navigation', 'Capell navigation is already registered.');
        }

        $return = $this->editablePanelReturn();
        if (! $return instanceof Return_) {
            return $this->manual('navigation', 'Add Capell navigation items and groups manually.');
        }

        $hasNavigationItems = $this->hasMethodCall('navigationItems');
        $hasNavigationGroups = $this->hasMethodCall('navigationGroups');

        if (($hasNavigationItems && ! $hasItems) || ($hasNavigationGroups && ! $hasGroups)) {
            return $this->manual('navigation', 'Merge CapellAdmin::getNavigationItems() and CapellAdmin::getNavigationGroups() into the existing navigation configuration manually.');
        }

        $this->editor->addUseStatements([CapellAdmin::class]);

        if (! $hasNavigationItems && ! $this->appendPanelMethodCall($return, 'navigationItems', [
            new Arg(new StaticCall(new Name('CapellAdmin'), 'getNavigationItems')),
        ])) {
            return $this->manual('navigation', 'Add Capell navigation items and groups manually.');
        }

        if (! $hasNavigationGroups && ! $this->appendPanelMethodCall($return, 'navigationGroups', [
            new Arg(new StaticCall(new Name('CapellAdmin'), 'getNavigationGroups')),
        ])) {
            return $this->manual('navigation', 'Add Capell navigation items and groups manually.');
        }

        return $this->applied('navigation', 'Added Capell navigation items and groups.');
    }

    public function addSitePermissionScopeMiddleware(): AdminPanelChangeResultData
    {
        if (str_contains($this->editor->originalContent(), 'SetSitePermissionScope::class')) {
            return $this->alreadyApplied('site-permission-scope', 'Site permission scope middleware is already registered.');
        }

        $return = $this->editablePanelReturn();
        if (! $return instanceof Return_) {
            return $this->manual('site-permission-scope', 'Add SetSitePermissionScope::class to the panel authMiddleware array manually.');
        }

        $existingAuthMiddleware = $this->findMethodCall('authMiddleware');
        $siteScopeMiddleware = new ArrayItem(new ClassConstFetch(new Name('SetSitePermissionScope'), 'class'));

        if ($existingAuthMiddleware instanceof MethodCall) {
            $existingAuthMiddlewareArray = $this->firstArrayArgument($existingAuthMiddleware);

            if (! $existingAuthMiddlewareArray instanceof Array_) {
                return $this->manual('site-permission-scope', 'Add SetSitePermissionScope::class to the existing authMiddleware configuration manually.');
            }

            $this->editor->addUseStatements([SetSitePermissionScope::class]);
            $existingAuthMiddlewareArray->items[] = $siteScopeMiddleware;

            return $this->applied('site-permission-scope', 'Added site permission scope middleware.');
        }

        $this->editor->addUseStatements([
            Authenticate::class,
            SetSitePermissionScope::class,
        ]);

        if (! $this->appendPanelMethodCall($return, 'authMiddleware', [
            new Arg(new Array_([
                new ArrayItem(new ClassConstFetch(new Name('Authenticate'), 'class')),
                $siteScopeMiddleware,
            ])),
        ])) {
            return $this->manual('site-permission-scope', 'Add SetSitePermissionScope::class to the panel authMiddleware array manually.');
        }

        return $this->applied('site-permission-scope', 'Added auth middleware with site permission scope.');
    }

    public function backup(): string
    {
        return $this->editor->backup();
    }

    public function save(): void
    {
        file_put_contents($this->panelPath, $this->preview());
    }

    public function preview(): string
    {
        return $this->formatPanelChain($this->editor->print());
    }

    public function path(): string
    {
        return $this->panelPath;
    }

    private function isDefaultFilamentDashboardPage(?ArrayItem $item): bool
    {
        if (! $item instanceof ArrayItem || ! $item->value instanceof ClassConstFetch) {
            return false;
        }

        $class = $item->value->class;

        if (! $class instanceof Name) {
            return false;
        }

        return $class->toString() === 'Dashboard'
            && str_contains($this->editor->originalContent(), 'use Filament\\Pages\\Dashboard;');
    }

    /**
     * @param  list<Arg>  $args
     */
    private function appendPanelMethodCall(Return_ $return, string $method, array $args): bool
    {
        if (! $return->expr instanceof Expr) {
            return false;
        }

        $return->expr = new MethodCall($return->expr, $method, $args);

        return true;
    }

    /**
     * @return array<int, array{in: string, for: string}>
     */
    private function defaultDiscoverConfigurators(): array
    {
        return [['in' => 'Filament/Configurators', 'for' => 'App\\Filament\\Configurators']];
    }

    private function editablePanelReturn(): ?Return_
    {
        if ($this->panelReturn instanceof Return_) {
            return $this->panelReturn;
        }

        $class = $this->editor->findClass();
        if (! $class instanceof Class_) {
            return null;
        }

        $panelMethod = $this->editor->findMethodInClass($class->name?->name, 'panel');
        if ($panelMethod?->stmts === null || count($panelMethod->stmts) !== 1) {
            return null;
        }

        $statement = $panelMethod->stmts[0];
        if (! $statement instanceof Return_ || ! $statement->expr instanceof MethodCall) {
            return null;
        }

        $this->panelReturn = $statement;

        return $this->panelReturn;
    }

    private function hasMethodCall(string $method): bool
    {
        return $this->findMethodCall($method) instanceof MethodCall;
    }

    private function findMethodCall(string $method): ?MethodCall
    {
        $return = $this->editablePanelReturn();
        $methodCall = $return?->expr;

        while ($methodCall instanceof MethodCall) {
            if ($methodCall->name instanceof Identifier && $methodCall->name->name === $method) {
                return $methodCall;
            }

            $methodCall = $methodCall->var;
        }

        return null;
    }

    private function firstArrayArgument(MethodCall $methodCall): ?Array_
    {
        $argument = $methodCall->args[0] ?? null;

        if (! $argument instanceof Arg || ! $argument->value instanceof Array_) {
            return null;
        }

        return $argument->value;
    }

    private function formatPanelChain(string $contents): string
    {
        return (string) preg_replace_callback(
            '/return\s+\$panel(?P<chain>.*?);/s',
            function (array $matches): string {
                $chain = preg_replace('/\)->/', ")\n            ->", $matches['chain']);
                $chain = preg_replace(
                    '/CapellAdminPlugin::make\(\)\n {12}->discoverConfigurators/',
                    "CapellAdminPlugin::make()\n                ->discoverConfigurators",
                    (string) $chain,
                );

                return 'return $panel' . $chain . ';';
            },
            $contents,
        );
    }

    private function applied(string $change, string $message): AdminPanelChangeResultData
    {
        return new AdminPanelChangeResultData($change, AdminPanelChangeStatus::Applied, $message);
    }

    private function alreadyApplied(string $change, string $message): AdminPanelChangeResultData
    {
        return new AdminPanelChangeResultData($change, AdminPanelChangeStatus::AlreadyApplied, $message);
    }

    private function manual(string $change, string $message): AdminPanelChangeResultData
    {
        return new AdminPanelChangeResultData(
            $change,
            AdminPanelChangeStatus::Manual,
            $message,
            AdminPanelFailureCategory::UnsupportedShape,
            self::DOCS_URL,
        );
    }
}
