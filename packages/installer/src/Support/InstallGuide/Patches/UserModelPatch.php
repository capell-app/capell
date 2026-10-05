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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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
        LogsActivity::class,
    ];

    private const string FILAMENT_USER_INTERFACE = FilamentUser::class;

    private const array VENDOR_LOGGING_TRAITS = [
        \Spatie\Activitylog\Traits\LogsActivity::class,
        'Spatie\\Activitylog\\Models\\Concerns\\LogsActivity',
    ];

    public function id(): string
    {
        return 'user-model-patch';
    }

    public function group(): string
    {
        return 'models';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.user_model_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.user_model_patch_description');
    }

    public function docUrl(): ?string
    {
        return null;
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

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
                return PatchStatus::Unsupported;
            }

            // Check if extends a non-stock base class
            if ($classNode->extends instanceof Name) {
                $extendsName = $this->getNodeName($classNode->extends);
                if ($extendsName !== User::class) {
                    return PatchStatus::Customised;
                }
            }

            $hasFilamentUser = $this->classImplementsInterface($classNode, self::FILAMENT_USER_INTERFACE);
            $requiredTraits = $this->requiredTraits();
            $presentTraits = $this->countPresentTraits($classNode, $requiredTraits);
            $totalRequiredTraits = count($requiredTraits);
            $activitylogMethod = $editor->findMethodInClass(self::CLASS_NAME, 'getActivitylogOptions');
            if ($activitylogMethod instanceof ClassMethod && ! $this->canAdaptActivitylogOptions($activitylogMethod)) {
                return PatchStatus::Customised;
            }

            $hasActivitylogMethod = $activitylogMethod instanceof ClassMethod;
            $hasCompatibleReturnType = $activitylogMethod?->returnType instanceof Name
                && in_array(strtolower($this->getNodeName($activitylogMethod->returnType)), [strtolower(LogOptions::class), strtolower(ActivityLogCompat::logOptionsClass())], true);

            $hasVendorLogging = $this->countPresentTraits($classNode, self::VENDOR_LOGGING_TRAITS) > 0;
            if ($hasFilamentUser && $presentTraits === $totalRequiredTraits && $hasActivitylogMethod && $hasCompatibleReturnType && ! $hasVendorLogging) {
                return PatchStatus::AlreadyApplied;
            }

            return PatchStatus::Applicable;
        } catch (RuntimeException) {
            return PatchStatus::Unsupported;
        }
    }

    public function reason(): ?string
    {
        return null;
    }

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
            $backupPath = $editor->backup();
            $this->resolveNames($editor);
            $traverser = new NodeTraverser(new ActivityLogNameVisitor);
            $editor->setAst($traverser->traverse($editor->getAst()));

            // Add use statements for the traits and interface
            $usesToAdd = [
                FilamentUser::class,
                ...($this->requiredTraits()),
                Activity::class,
                LogOptions::class,
                ActivityLogCompat::class,
            ];

            $editor->addUseStatements(array_values(array_filter($usesToAdd, fn (string $use): bool => $this->canImport($editor, $use))));
            $this->resolveNames($editor);

            // Find the class and add interface + traits
            $classNode = $editor->findClass(self::CLASS_NAME);
            throw_unless($classNode instanceof Class_, RuntimeException::class, 'Could not find User class in the file');

            // Add FilamentUser to implements
            $this->addInterfaceToClass($editor, $classNode);

            // Add traits to the class
            $this->addTraitsToClass($editor);

            // Add the getActivitylogOptions method
            $this->addActivitylogOptionsMethod($editor);

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
     * @return array<string>
     */
    private function requiredTraits(): array
    {
        return self::ADMIN_TRAITS;
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
        $loggingTraitUse = null;
        $existingTraitNames = [];

        foreach ($classNode->stmts as $index => $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $traitIndex => $traitNode) {
                    $traitName = strtolower($this->getNodeName($traitNode));
                    if ($traitName === strtolower(LogsActivity::class) && isset($existingTraitNames[$traitName])) {
                        if ($loggingTraitUse instanceof TraitUse && $loggingTraitUse !== $stmt) {
                            $loggingTraitUse->adaptations = [...$loggingTraitUse->adaptations, ...$stmt->adaptations];
                            $stmt->adaptations = [];
                        }

                        unset($stmt->traits[$traitIndex]);

                        continue;
                    }

                    if ($traitName === strtolower(LogsActivity::class)) {
                        $loggingTraitUse = $stmt;
                    }

                    $existingTraitNames[$traitName] = true;
                }

                $stmt->traits = array_values($stmt->traits);
                if ($stmt->traits === []) {
                    unset($classNode->stmts[$index]);
                } else {
                    $traitUse = $stmt;
                }
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

        $methodExists = array_any($classNode->stmts, fn (mixed $stmt): bool => $stmt instanceof ClassMethod && $stmt->name->name === 'getActivitylogOptions');

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
        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]));
        $editor->setAst($traverser->traverse($editor->getAst()));
    }

    private function canAdaptActivitylogOptions(ClassMethod $method): bool
    {
        return $method->isPublic() && ! $method->isStatic() && ! $method->isAbstract()
            && $method->returnType instanceof Name
            && in_array(strtolower($this->getNodeName($method->returnType)), [
                strtolower(LogOptions::class),
                'spatie\\activitylog\\logoptions',
                'spatie\\activitylog\\support\\logoptions',
            ], true)
            && ! array_any($method->params, static fn (Param $parameter): bool => ! $parameter->default instanceof Expr && ! $parameter->variadic);
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

    private function canImport(PhpFileEditor $editor, string $class): bool
    {
        if (! $this->referenceName($editor, $class) instanceof FullyQualified) {
            return false;
        }

        $shortName = class_basename($class);
        $collision = (new NodeFinder)->findFirst($editor->getAst(), fn (Node $node): bool => $node instanceof Name
            && strcasecmp($node->toString(), $shortName) === 0
            && strcasecmp($this->getNodeName($node), $class) !== 0);

        return ! $collision instanceof Node;
    }
}
