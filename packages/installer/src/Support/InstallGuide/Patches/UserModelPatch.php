<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Filament\Models\Contracts\FilamentUser;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\ParserFactory;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
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

    private const string FILAMENT_USER_INTERFACE = 'FilamentUser';

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
            $classNode = $editor->findClass(self::CLASS_NAME);

            if (! $classNode instanceof Class_) {
                return PatchStatus::Unsupported;
            }

            // Check if extends a non-stock base class
            if ($classNode->extends instanceof Name) {
                $extendsName = $this->getNodeName($classNode->extends);
                if ($extendsName !== 'Authenticatable') {
                    return PatchStatus::Customised;
                }
            }

            $hasFilamentUser = $this->classImplementsInterface($classNode, self::FILAMENT_USER_INTERFACE);
            $requiredTraits = $this->requiredTraits();
            $presentTraits = $this->countPresentTraits($classNode, $requiredTraits);
            $totalRequiredTraits = count($requiredTraits);
            $hasActivitylogMethod = $editor->findMethodInClass(self::CLASS_NAME, 'getActivitylogOptions') instanceof ClassMethod;

            if ($hasFilamentUser && $presentTraits === $totalRequiredTraits && $hasActivitylogMethod) {
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

            // Add use statements for the traits and interface
            $usesToAdd = [
                FilamentUser::class,
                ...($this->requiredTraits()),
                Activity::class,
                LogOptions::class,
            ];

            $editor->addUseStatements($usesToAdd);

            // Find the class and add interface + traits
            $classNode = $editor->findClass(self::CLASS_NAME);
            throw_unless($classNode instanceof Class_, RuntimeException::class, 'Could not find User class in the file');

            // Add FilamentUser to implements
            $this->addInterfaceToClass($classNode, self::FILAMENT_USER_INTERFACE);

            // Add traits to the class
            $this->addTraitsToClass($classNode);

            // Add the getActivitylogOptions method
            $this->addActivitylogOptionsMethod($classNode);

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
            return $node->toString();
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
            if ($implementedName === $interfaceName || str_ends_with($implementedName, '\\' . $interfaceName)) {
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
                    // Match short names ('HasRoles') or fully-qualified names
                    foreach ($requiredTraits as $requiredTrait) {
                        if ($requiredTrait === $traitName || str_ends_with($requiredTrait, '\\' . $traitName)) {
                            $presentTraits[$requiredTrait] = true;
                            break;
                        }
                    }
                }
            }
        }

        return count($presentTraits);
    }

    private function addInterfaceToClass(Class_ $classNode, string $interfaceName): void
    {
        if ($classNode->implements === null) {
            $classNode->implements = [];
        }

        // Check if already present
        foreach ($classNode->implements as $implement) {
            if ($this->getNodeName($implement) === $interfaceName) {
                return;
            }
        }

        $classNode->implements[] = new Name($interfaceName);
    }

    private function addTraitsToClass(Class_ $classNode): void
    {
        if ($classNode->stmts === null) {
            $classNode->stmts = [];
        }

        // Find existing trait uses to know where to insert
        $traitUseIndex = null;
        $existingTraitNames = [];

        foreach ($classNode->stmts as $index => $stmt) {
            if ($stmt instanceof TraitUse) {
                $traitUseIndex = $index;
                foreach ($stmt->traits as $traitNode) {
                    $traitName = $this->getNodeName($traitNode);
                    $existingTraitNames[$traitName] = true;
                }
            }
        }

        // Build the list of traits to add
        $traitsToAdd = [];
        foreach ($this->requiredTraits() as $requiredTrait) {
            $parts = explode('\\', $requiredTrait);
            $shortName = end($parts);
            if (! isset($existingTraitNames[$shortName])) {
                $traitsToAdd[] = new Name($shortName);
            }
        }

        if ($traitsToAdd === []) {
            return;
        }

        if ($traitUseIndex !== null) {
            // Append to existing trait use
            $traitUseStmt = $classNode->stmts[$traitUseIndex];
            if ($traitUseStmt instanceof TraitUse) {
                $traitUseStmt->traits = array_merge(
                    $traitUseStmt->traits,
                    $traitsToAdd,
                );
            }
        } else {
            // Create a new TraitUse statement at the beginning of the class body
            $newTraitUse = new TraitUse($traitsToAdd);
            array_unshift($classNode->stmts, $newTraitUse);
        }
    }

    private function addActivitylogOptionsMethod(Class_ $classNode): void
    {
        if ($classNode->stmts === null) {
            $classNode->stmts = [];
        }

        $methodExists = array_any($classNode->stmts, fn (mixed $stmt): bool => $stmt instanceof ClassMethod && $stmt->name->name === 'getActivitylogOptions');

        if ($methodExists) {
            return;
        }

        // Create the method using raw PHP code parsing
        $methodCode = <<<'PHP'
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->useLogName('user')
        ->logAll()
        ->logExcept(['email_verified_at', 'password', 'remember_token', 'updated_at', 'created_at'])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs();
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
}
