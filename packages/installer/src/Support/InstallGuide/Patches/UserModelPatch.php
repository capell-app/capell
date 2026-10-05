<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Core\Support\Activity\ActivityLogCompat;
use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\LogsActivity;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Auth\User;
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

class UserModelPatch implements Patch
{
    private const string USER_MODEL_PATH = 'app/Models/User.php';

    private const string CLASS_NAME = 'User';

    private const array ADMIN_TRAITS = [
        HasImpersonation::class,
        HasPanelShield::class,
        HasRoles::class,
        // Site-scoped permission helpers (isGlobalAdmin(), etc.). Without this the
        // patched User 500s on admin requests that resolve global-admin status.
        HasSitePermissions::class,
    ];

    private const string FILAMENT_USER_INTERFACE = FilamentUser::class;

    #[Override]
    public function id(): string
    {
        return 'user-model-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'models';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.user_model_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.user_model_patch_description');
    }

    #[Override]
    public function docUrl(): ?string
    {
        return null;
    }

    #[Override]
    public function defaultEnabled(): bool
    {
        return true;
    }

    #[Override]
    public function probe(): PatchStatus
    {
        $userModelPath = base_path(self::USER_MODEL_PATH);

        if (! file_exists($userModelPath)) {
            return PatchStatus::Unsupported;
        }

        try {
            $editor = new PhpFileEditor($userModelPath);
            $this->resolveNames($editor);
            $classNode = $editor->findClass(self::CLASS_NAME);

            if (! $classNode instanceof Class_) {
                return PatchStatus::Customised;
            }

            // A file-wide edit must never affect another declaration or namespace.
            $finder = new NodeFinder;
            $namespaces = $finder->findInstanceOf($editor->getAst(), Namespace_::class);
            $types = $finder->findInstanceOf($editor->getAst(), ClassLike::class);
            if (count($namespaces) !== 1 || $namespaces[0]->name?->toString() !== 'App\\Models'
                || count($types) !== 1 || $finder->findInstanceOf($editor->getAst(), Function_::class) !== []
                || ! $this->hasDeclarationOnlyFile($editor, $namespaces[0])
                || $classNode->isAbstract() || $classNode->isReadonly()
                || ! $classNode->extends instanceof Name || $this->getNodeName($classNode->extends) !== User::class
                || ! $this->hasPortableUserShape($classNode) || ! $this->hasSafeImports($editor)) {
                return PatchStatus::Customised;
            }

            $options = $classNode->getMethod('getActivitylogOptions');
            if ($this->classImplementsInterface($classNode, self::FILAMENT_USER_INTERFACE)
                && $this->countPresentTraits($classNode, $this->requiredTraits()) === count($this->requiredTraits())
                && $options instanceof ClassMethod && $this->hasPortableActivitylogOptions($options)
                && strcasecmp($this->getNodeName($options->returnType), LogOptions::class) === 0
                && ! $this->hasUnexpectedLoggingReferences($editor, $options)) {
                return PatchStatus::AlreadyApplied;
            }

            // Existing logging belongs to the host. Never rewrite its imports,
            // options, hooks or executable regions, even when they look portable.
            return $this->hasLoggingReferences($editor->getAst())
                ? PatchStatus::Customised
                : PatchStatus::Applicable;
        } catch (RuntimeException) {
            return PatchStatus::Unsupported;
        }
    }

    /**
     * Readiness belongs to the installed major, while probe() deliberately only
     * recognises portable preparation. Existing host logging must never be edited.
     */
    public function isReadyForAdmin(): bool
    {
        $path = base_path(self::USER_MODEL_PATH);
        if (! is_file($path)) {
            return false;
        }

        try {
            $editor = new PhpFileEditor($path);
            $this->resolveNames($editor);
            $class = $editor->findClass(self::CLASS_NAME);
            if (! $class instanceof Class_ || $class->isAbstract() || $class->isReadonly()
                || ! $class->extends instanceof Name || $this->getNodeName($class->extends) !== User::class
                || ! $this->classImplementsInterface($class, self::FILAMENT_USER_INTERFACE)
                || $this->countPresentTraits($class, self::ADMIN_TRAITS) !== count(self::ADMIN_TRAITS)) {
                return false;
            }

            $namespaces = (new NodeFinder)->findInstanceOf($editor->getAst(), Namespace_::class);
            if (! array_any($namespaces, static fn (Namespace_ $namespace): bool => $namespace->name?->toString() === 'App\\Models'
                && in_array($class, $namespace->stmts, true))) {
                return false;
            }

            $panelAccess = $class->getMethod('canAccessPanel');
            if ($panelAccess instanceof ClassMethod && ! $this->hasValidPanelAccessMethod($panelAccess)) {
                return false;
            }

            $loggingTraits = [LogsActivity::class, ActivityLogCompat::logsActivityTrait()];
            $presentLoggingTraits = 0;
            foreach ($class->getTraitUses() as $use) {
                if ($use->adaptations !== []) {
                    return false;
                }

                foreach ($use->traits as $trait) {
                    if (array_any($loggingTraits, fn (string $name): bool => strcasecmp($this->getNodeName($trait), $name) === 0)) {
                        $presentLoggingTraits++;
                    } elseif ($this->isLoggingName($this->getNodeName($trait))) {
                        return false;
                    }
                }
            }

            $options = $class->getMethod('getActivitylogOptions');

            return $presentLoggingTraits === 1 && $options instanceof ClassMethod
                && $this->hasValidActivitylogOptions($options, installedMajor: true)
                && ! $this->hasLoggingReferences($editor->getAst(), installedMajor: true);
        } catch (RuntimeException) {
            return false;
        }
    }

    #[Override]
    public function reason(): ?string
    {
        if ($this->probe() !== PatchStatus::Customised) {
            return null;
        }

        $reason = __('capell-installer::install-guide.user_model_patch_customised');
        throw_unless(is_string($reason), RuntimeException::class, 'User model guidance must be a translation string.');

        return $reason;
    }

    #[Override]
    public function apply(): void
    {
        $userModelPath = base_path(self::USER_MODEL_PATH);

        throw_unless(file_exists($userModelPath), RuntimeException::class, 'User model not found at: ' . $userModelPath);

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(
                'Cannot apply patch when status is: ' . $status->value,
            );
        }

        try {
            $editor = new PhpFileEditor($userModelPath);
            $editor->backup();
            $this->resolveNames($editor);

            // Add use statements for the traits and interface
            $editor->addUseStatements(array_values(array_filter(
                $this->importsToAdd(),
                fn (string $use): bool => $this->referenceName($editor, $use) instanceof FullyQualified,
            )));
            $this->resolveNames($editor);

            // Find the class and add interface + traits
            $classNode = $editor->findClass(self::CLASS_NAME);
            throw_unless($classNode instanceof Class_, RuntimeException::class, 'Could not find User class in the file');

            // Add FilamentUser to implements
            $this->addInterfaceToClass($editor, $classNode, self::FILAMENT_USER_INTERFACE);

            // Add traits to the class
            $this->addTraitsToClass($editor, $classNode);

            // Add the getActivitylogOptions method
            $this->addActivitylogOptionsMethod($editor, $classNode);

            $editor->save();
            clearstatcache(true, $userModelPath);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($userModelPath, true);
            }
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply UserModelPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function getNodeName(Node $node): string
    {
        if ($node instanceof Name) {
            $resolved = $node->getAttribute('resolvedName');

            return $resolved instanceof Name ? $resolved->toString() : $node->toString();
        }

        if (property_exists($node, 'name') && is_string($node->name)) {
            return $node->name;
        }

        return '';
    }

    private function classImplementsInterface(Class_ $classNode, string $interfaceName): bool
    {
        if ($classNode->implements === null) {
            return false;
        }

        foreach ($classNode->implements as $implement) {
            $implementedName = $this->getNodeName($implement);
            if (strcasecmp($implementedName, $interfaceName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function requiredTraits(): array
    {
        return [...self::ADMIN_TRAITS, LogsActivity::class];
    }

    /**
     * @param  array<string>  $requiredTraits
     */
    private function countPresentTraits(Class_ $classNode, array $requiredTraits): int
    {
        if ($classNode->stmts === null) {
            return 0;
        }

        $presentTraits = [];

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $traitNode) {
                    $traitName = $this->getNodeName($traitNode);
                    foreach ($requiredTraits as $requiredTrait) {
                        if (strcasecmp($requiredTrait, $traitName) === 0) {
                            $presentTraits[$requiredTrait] = true;
                            break;
                        }
                    }
                }
            }
        }

        return count($presentTraits);
    }

    private function addInterfaceToClass(PhpFileEditor $editor, Class_ $classNode, string $interfaceName): void
    {
        if ($classNode->implements === null) {
            $classNode->implements = [];
        }

        // Check if already present
        foreach ($classNode->implements as $implement) {
            if (strcasecmp($this->getNodeName($implement), $interfaceName) === 0) {
                return;
            }
        }

        $classNode->implements[] = $this->referenceName($editor, $interfaceName);
    }

    private function addTraitsToClass(PhpFileEditor $editor, Class_ $classNode): void
    {
        if ($classNode->stmts === null) {
            $classNode->stmts = [];
        }

        // Find existing trait uses to know where to insert
        $traitUse = null;
        $existingTraitNames = [];

        foreach ($classNode->getTraitUses() as $statement) {
            $traitUse = $statement;
            foreach ($statement->traits as $trait) {
                $existingTraitNames[strtolower($this->getNodeName($trait))] = true;
            }
        }

        // Build the list of traits to add
        $traitsToAdd = [];
        foreach ($this->requiredTraits() as $requiredTrait) {
            if (! isset($existingTraitNames[strtolower($requiredTrait)])) {
                $traitsToAdd[] = $this->referenceName($editor, $requiredTrait);
            }
        }

        $classNode->stmts = array_values($classNode->stmts);
        if ($traitsToAdd === []) {
            return;
        }

        if ($traitUse instanceof TraitUse) {
            // Append to existing trait use
            $traitUse->traits = array_merge($traitUse->traits, $traitsToAdd);
        } else {
            // Create a new TraitUse statement at the beginning of the class body
            $newTraitUse = new TraitUse($traitsToAdd);
            array_unshift($classNode->stmts, $newTraitUse);
        }
    }

    private function addActivitylogOptionsMethod(PhpFileEditor $editor, Class_ $classNode): void
    {
        if ($classNode->stmts === null) {
            $classNode->stmts = [];
        }

        $methodExists = array_any($classNode->stmts, fn (mixed $stmt): bool => $stmt instanceof ClassMethod && strcasecmp($stmt->name->name, 'getActivitylogOptions') === 0);

        if ($methodExists) {
            return;
        }

        // Create the method using raw PHP code parsing
        $optionsName = $this->referenceName($editor, LogOptions::class)->toCodeString();
        $compatName = $this->referenceName($editor, ActivityLogCompat::class)->toCodeString();
        $methodCode = <<<PHP
public function getActivitylogOptions(): {$optionsName}
{
    return {$compatName}::options('user', ['email_verified_at', 'password', 'remember_token', 'updated_at', 'created_at']);
}
PHP;

        // Wrap in a temporary class so visibility modifiers parse correctly
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $wrapperAst = $parser->parse('<?php class _Tmp { ' . $methodCode . ' }');

        if (
            count($wrapperAst) > 0
            && $wrapperAst[0] instanceof Class_
            && $wrapperAst[0]->stmts !== []
            && $wrapperAst[0]->stmts[0] instanceof ClassMethod
        ) {
            $classNode->stmts[] = $wrapperAst[0]->stmts[0];
        }
    }

    private function resolveNames(PhpFileEditor $editor): void
    {
        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]), new ParentConnectingVisitor);
        $editor->setAst($traverser->traverse($editor->getAst()));

        // NameResolver leaves import names untouched, including grouped prefixes.
        foreach ((new NodeFinder)->findInstanceOf($editor->getAst(), GroupUse::class) as $group) {
            $group->prefix->setAttribute('loggingNamespacePrefix', true);
            foreach ($group->uses as $use) {
                $use->name->setAttribute('resolvedName', new FullyQualified($group->prefix->toString() . '\\' . $use->name->toString()));
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($editor->getAst(), FuncCall::class) as $call) {
            if ($call->name instanceof Name) {
                $call->name->setAttribute('loggingFunctionReference', true);
            }
        }
    }

    private function hasPortableUserShape(Class_ $class): bool
    {
        $allowed = array_map(strtolower(...), [
            ...$this->requiredTraits(),
            'Illuminate\\Notifications\\Notifiable',
            'Illuminate\\Database\\Eloquent\\Factories\\HasFactory',
            'Illuminate\\Database\\Eloquent\\SoftDeletes',
        ]);
        $seen = [];
        foreach ($class->getTraitUses() as $use) {
            // Precedence and aliases can refer to removed methods or collapse to
            // self-exclusion after replacement. Preserve them for manual review.
            if ($use->adaptations !== []) {
                return false;
            }

            foreach ($use->traits as $trait) {
                $name = strtolower($this->getNodeName($trait));
                if (! in_array($name, $allowed, true) || isset($seen[$name])) {
                    return false;
                }

                $seen[$name] = true;
            }
        }

        foreach ($class->implements as $interface) {
            if (strcasecmp($this->getNodeName($interface), self::FILAMENT_USER_INTERFACE) !== 0) {
                return false;
            }
        }

        // Custom hooks and overrides may depend on either major's internals.
        foreach ($class->getMethods() as $method) {
            if (! in_array(strtolower($method->name->name), ['getactivitylogoptions', 'casts', 'canaccesspanel'], true)) {
                return false;
            }

            if (strcasecmp($method->name->name, 'casts') === 0 && ($method->isPrivate() || $method->isStatic() || $method->isAbstract()
                || $method->byRef || $method->params !== [] || ! $method->returnType instanceof Identifier || $method->returnType->name !== 'array')) {
                return false;
            }

            if (strcasecmp($method->name->name, 'canAccessPanel') === 0 && ! $this->hasValidPanelAccessMethod($method)) {
                return false;
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->type !== null || $property->isPrivate() || $property->isStatic() || $property->isReadonly()) {
                return false;
            }

            foreach ($property->props as $item) {
                if (! in_array($item->name->name, ['fillable', 'guarded', 'hidden', 'casts', 'table', 'connection', 'timestamps'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasValidPanelAccessMethod(ClassMethod $method): bool
    {
        return $method->isPublic() && ! $method->isStatic() && ! $method->isAbstract() && ! $method->byRef
            && $method->returnType instanceof Identifier && $method->returnType->name === 'bool'
            && count($method->params) === 1 && $method->params[0]->type instanceof Name
            && $this->getNodeName($method->params[0]->type) === 'Filament\\Panel'
            && ! $method->params[0]->byRef && ! $method->params[0]->variadic;
    }

    private function hasPortableActivitylogOptions(ClassMethod $method): bool
    {
        return $this->hasValidActivitylogOptions($method, installedMajor: false);
    }

    private function hasValidActivitylogOptions(ClassMethod $method, bool $installedMajor): bool
    {
        $optionsClasses = $installedMajor ? [LogOptions::class, ActivityLogCompat::logOptionsClass()] : [LogOptions::class];

        return $method->isPublic() && ! $method->isStatic() && ! $method->isAbstract() && ! $method->byRef
            && $method->attrGroups === []
            && $method->params === []
            && $method->returnType instanceof Name
            && array_any($optionsClasses, fn (string $name): bool => strcasecmp($this->getNodeName($method->returnType), $name) === 0)
            && count($method->stmts ?? []) === 1
            && $method->stmts[0] instanceof Return_
            && $method->stmts[0]->expr instanceof Expr
            && $this->isValidOptions($method->stmts[0]->expr, $installedMajor);
    }

    private function isValidOptions(Expr $expression, bool $installedMajor): bool
    {
        if ($expression instanceof MethodCall && $expression->name instanceof Identifier) {
            $name = strtolower($expression->name->name);
            $arguments = $expression->args;
            $valid = match ($name) {
                'logall', 'logunguarded', 'logfillable', 'dontlogfillable', 'logonlydirty' => $arguments === [],
                'logonly', 'logexcept', 'dontlogifattributeschangedonly', 'useattributerawvalues' => count($arguments) === 1 && $this->isStringListArgument($arguments[0]),
                'dontsubmitemptylogs', 'submitemptylogs', 'dontlogemptychanges', 'logemptychanges' => $installedMajor
                    && method_exists(ActivityLogCompat::logOptionsClass(), $name) && $arguments === [],
                'uselogname' => count($arguments) === 1 && $this->isStringArgument($arguments[0]),
                default => false,
            };

            return $valid && $this->isValidOptions($expression->var, $installedMajor);
        }

        if (! $expression instanceof StaticCall || ! $expression->class instanceof Name || ! $expression->name instanceof Identifier) {
            return false;
        }

        $class = strtolower($this->getNodeName($expression->class));
        $name = strtolower($expression->name->name);
        if ($class === strtolower(ActivityLogCompat::class)) {
            return match ($name) {
                'options' => count($expression->args) === 2
                    && $this->isStringArgument($expression->args[0]) && $this->isStringListArgument($expression->args[1]),
                'withoutemptylogs' => count($expression->args) === 1 && $expression->args[0] instanceof Arg
                    && ! $expression->args[0]->unpack && ! $expression->args[0]->byRef && ! $expression->args[0]->name instanceof Identifier
                    && $this->isValidOptions($expression->args[0]->value, $installedMajor),
                default => false,
            };
        }

        $optionsClasses = $installedMajor ? [LogOptions::class, ActivityLogCompat::logOptionsClass()] : [LogOptions::class];

        return in_array($class, array_map(strtolower(...), $optionsClasses), true)
            && $name === 'defaults' && $expression->args === [];
    }

    private function isStringArgument(Node $argument): bool
    {
        return $argument instanceof Arg && ! $argument->unpack && ! $argument->byRef && ! $argument->name instanceof Identifier
            && $argument->value instanceof String_;
    }

    private function isStringListArgument(Node $argument): bool
    {
        return $argument instanceof Arg && ! $argument->unpack && ! $argument->byRef && ! $argument->name instanceof Identifier
            && $argument->value instanceof Array_
            && array_all($argument->value->items, static fn (ArrayItem $item): bool => $item instanceof ArrayItem
                && ! $item->unpack && ! $item->byRef && ! $item->key instanceof Expr && $item->value instanceof String_);
    }

    private function referenceName(PhpFileEditor $editor, string $class): Name
    {
        foreach ((new NodeFinder)->find($editor->getAst(), static fn (Node $node): bool => $node instanceof Use_ || $node instanceof GroupUse) as $statement) {
            if (! $statement instanceof Use_ && ! $statement instanceof GroupUse) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $name = $statement instanceof GroupUse ? $statement->prefix->toString() . '\\' . $use->name->toString() : $use->name->toString();
                if (($statement->type | $use->type) === Use_::TYPE_NORMAL && strcasecmp($name, $class) === 0) {
                    return new Name($use->getAlias()->toString());
                }
            }
        }

        return new FullyQualified($class);
    }

    /** @return list<class-string> */
    private function importsToAdd(): array
    {
        return [FilamentUser::class, ...$this->requiredTraits(), Activity::class, LogOptions::class, ActivityLogCompat::class];
    }

    private function hasSafeImports(PhpFileEditor $editor): bool
    {
        $reserved = [];
        foreach ((new NodeFinder)->find($editor->getAst(), static fn (Node $node): bool => $node instanceof Use_ || $node instanceof GroupUse) as $statement) {
            if (! $statement instanceof Use_ && ! $statement instanceof GroupUse) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $alias = strtolower($use->getAlias()->toString());
                $name = $statement instanceof GroupUse ? $statement->prefix->toString() . '\\' . $use->name->toString() : $use->name->toString();
                // Function and constant aliases are also reserved: keep the
                // patch's generated import names unambiguous in every bucket.
                $reserved[$alias] = ($statement->type | $use->type) === Use_::TYPE_NORMAL ? strtolower($name) : '';
            }
        }

        foreach ($this->importsToAdd() as $class) {
            $alias = strtolower(class_basename($class));
            if (isset($reserved[$alias]) && $reserved[$alias] !== strtolower($class)) {
                return false;
            }

            // Adding an import must not rebind a previously unqualified name.
            $collision = (new NodeFinder)->findFirst($editor->findClass(self::CLASS_NAME), fn (Node $node): bool => $node instanceof Name
                && strcasecmp($node->toString(), $alias) === 0
                && strcasecmp($this->getNodeName($node), $class) !== 0);
            if ($collision instanceof Node) {
                return false;
            }
        }

        return true;
    }

    private function hasDeclarationOnlyFile(PhpFileEditor $editor, Namespace_ $namespace): bool
    {
        foreach ($editor->getAst() as $statement) {
            if ($statement !== $namespace && ! $statement instanceof Declare_ && ! $statement instanceof Nop) {
                return false;
            }

            if ($statement instanceof Declare_ && $statement->stmts !== null) {
                return false;
            }
        }

        return array_all($namespace->stmts, static fn (Node $node): bool => $node instanceof Class_
            || $node instanceof Use_ || $node instanceof GroupUse || $node instanceof Nop);
    }

    /**
     * The same file-wide scanner refuses edits to host logging and rejects
     * readiness when a reference cannot resolve on the installed major.
     *
     * @param  Node|Node[]  $nodes
     */
    private function hasLoggingReferences(Node|array $nodes, bool $installedMajor = false): bool
    {
        return (new NodeFinder)->findFirst($nodes, function (Node $node) use ($installedMajor): bool {
            if ($node instanceof Name) {
                if ($node->getAttribute('loggingNamespacePrefix') === true) {
                    return false;
                }

                $name = $this->getNodeName($node);

                return $this->isLoggingName($name) && (! $installedMajor || ! $this->isInstalledLoggingName($name, $node));
            }

            if ($node instanceof Identifier) {
                return ! $installedMajor && $this->isLoggingName($node->name);
            }

            if ($node instanceof StaticCall || $node instanceof ClassConstFetch || $node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
                $class = $node instanceof MethodCall || $node instanceof NullsafeMethodCall ? $this->loggingExpressionClass($node->var)
                    : ($node->class instanceof Name ? $this->getNodeName($node->class) : $this->loggingExpressionClass($node->class));
                if ($class !== null && $this->isLoggingName($class)) {
                    return ! $installedMajor || ! $node->name instanceof Identifier
                        || ($node instanceof ClassConstFetch
                            ? strcasecmp($node->name->name, 'class') !== 0 && ! defined($class . '::' . $node->name->name)
                            : ! method_exists($class, $node->name->name));
                }
            }

            return $node instanceof String_ && $this->isLoggingName($node->value)
                && (! $installedMajor || (str_contains($node->value, '\\') && ! $this->isInstalledLoggingName($node->value, $node)));
        }) instanceof Node;
    }

    private function isInstalledLoggingName(string $name, Node $node): bool
    {
        if ($node instanceof Name && $node->getAttribute('loggingFunctionReference') === true) {
            return function_exists($name) || (! $node instanceof FullyQualified && function_exists($node->toString()));
        }

        return class_exists($name) || interface_exists($name) || trait_exists($name);
    }

    private function loggingExpressionClass(Expr $expression): ?string
    {
        if ($expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
            if ($expression->var instanceof Variable && $expression->var->name === 'this' && $expression->name instanceof Identifier) {
                $scope = $expression->getAttribute('parent');
                while ($scope instanceof Node) {
                    if ($scope instanceof Class_) {
                        return $this->declaredLoggingClass($scope->getMethod($expression->name->name)?->returnType);
                    }

                    $scope = $scope->getAttribute('parent');
                }
            }

            return $this->loggingExpressionClass($expression->var);
        }

        if ($expression instanceof Variable && is_string($expression->name)) {
            $scope = $expression->getAttribute('parent');
            while ($scope instanceof Node) {
                if ($scope instanceof FunctionLike) {
                    // A local assignment takes precedence over a parameter type.
                    $assignments = (new NodeFinder)->findInstanceOf($scope->getStmts() ?? [], Assign::class);
                    foreach (array_reverse($assignments) as $assignment) {
                        if ($assignment->var instanceof Variable && $assignment->var->name === $expression->name
                            && $assignment->getEndFilePos() < $expression->getStartFilePos()) {
                            return $this->loggingExpressionClass($assignment->expr);
                        }
                    }

                    foreach ($scope->getParams() as $parameter) {
                        if ($parameter->var instanceof Variable && $parameter->var->name === $expression->name) {
                            return $this->declaredLoggingClass($parameter->type);
                        }
                    }

                    return null;
                }

                $scope = $scope->getAttribute('parent');
            }
        }

        if ($expression instanceof PropertyFetch && $expression->var instanceof Variable
            && $expression->var->name === 'this' && $expression->name instanceof Identifier) {
            $scope = $expression->getAttribute('parent');
            while ($scope instanceof Node) {
                if ($scope instanceof Class_) {
                    foreach ($scope->getProperties() as $property) {
                        foreach ($property->props as $item) {
                            if ($item->name->name === $expression->name->name) {
                                return $this->declaredLoggingClass($property->type);
                            }
                        }
                    }

                    return null;
                }

                $scope = $scope->getAttribute('parent');
            }
        }

        if ($expression instanceof String_) {
            return $this->isLoggingName($expression->value) ? $expression->value : null;
        }

        if ($expression instanceof ClassConstFetch && $expression->class instanceof Name
            && $expression->name instanceof Identifier && strcasecmp($expression->name->name, 'class') === 0) {
            return $this->declaredLoggingClass($expression->class);
        }

        if (($expression instanceof StaticCall || $expression instanceof New_) && $expression->class instanceof Name) {
            $class = $this->getNodeName($expression->class);
            if ($class === ActivityLogCompat::class && $expression instanceof StaticCall && $expression->name instanceof Identifier) {
                return match (strtolower($expression->name->name)) {
                    'options' => LogOptions::class,
                    'withoutemptylogs' => isset($expression->args[0]) && $expression->args[0] instanceof Arg
                        ? $this->loggingExpressionClass($expression->args[0]->value) : null,
                    default => null,
                };
            }

            return $this->isLoggingName($class) ? $class : null;
        }

        return null;
    }

    private function declaredLoggingClass(?Node $type): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                $class = $this->declaredLoggingClass($member);
                if ($class !== null) {
                    return $class;
                }
            }

            return null;
        }

        return $type instanceof Name && $this->isLoggingName($this->getNodeName($type)) ? $this->getNodeName($type) : null;
    }

    private function isLoggingName(string $name): bool
    {
        return preg_match('/activitylog|(?:^|\\\\)(?:logsactivity|logoptions|activitylogcompat|activity|tapactivity|beforeactivitylogged|getactivitylogoptions)$/i', $name) === 1;
    }

    private function hasUnexpectedLoggingReferences(PhpFileEditor $editor, ClassMethod $options): bool
    {
        // Complete preparation is recognised, never transformed. Only the Core
        // logging imports, trait and validated options method may mention logging.
        $allowed = array_map(strtolower(...), [LogsActivity::class, LogOptions::class, ActivityLogCompat::class, Activity::class]);
        foreach ((new NodeFinder)->find($editor->getAst(), static fn (Node $node): bool => $node instanceof Use_ || $node instanceof GroupUse) as $statement) {
            if (! $statement instanceof Use_ && ! $statement instanceof GroupUse) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $name = $statement instanceof GroupUse ? $statement->prefix->toString() . '\\' . $use->name->toString() : $use->name->toString();
                if ($this->isLoggingName($name) && ! in_array(strtolower($name), $allowed, true)) {
                    return true;
                }
            }
        }

        $class = $editor->findClass(self::CLASS_NAME);
        if (! $class instanceof Class_) {
            return true;
        }

        foreach ($class->stmts as $statement) {
            if ($statement === $options) {
                continue;
            }

            if ($statement instanceof TraitUse) {
                continue;
            }

            if ($this->hasLoggingReferences($statement)) {
                return true;
            }
        }

        return false;
    }
}
