<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionNamedType;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Type\ObjectType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use function in_array;
use function is_array;
use function is_string;
use function str_ends_with;
use function str_starts_with;
use function strlen;

final class EloquentUsageProvider implements MemberUsageProvider
{

    private const OBSERVER_EVENT_METHODS = [
        'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored', 'replicating',
        'retrieved', 'forceDeleting', 'forceDeleted', 'trashed',
    ];

    private readonly bool $enabled;

    public function __construct(
        ?bool $enabled,
    )
    {
        $this->enabled = $enabled ?? InstalledVersions::isInstalled('illuminate/database');
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [...$usages, ...$this->getMethodUsagesFromReflection($node)];
            $usages = [...$usages, ...$this->getObserverUsagesFromModelAttribute($node)];
        }

        if ($node instanceof StaticCall) {
            $usages = [...$usages, ...$this->getUsagesFromObserveCall($node, $scope)];
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getMethodUsagesFromReflection(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $note = $this->shouldMarkAsUsed($method, $classReflection);

            if ($note !== null) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()), $note);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromObserveCall(
        StaticCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Identifier || $node->name->name !== 'observe') {
            return [];
        }

        $callerType = $node->class instanceof Expr
            ? $scope->getType($node->class)
            : $scope->resolveTypeByName($node->class);

        if (!(new ObjectType('Illuminate\Database\Eloquent\Model'))->isSuperTypeOf($callerType)->yes()) {
            return [];
        }

        $arg = $node->getArgs()[0] ?? null;

        if ($arg === null) {
            return [];
        }

        $argType = $scope->getType($arg->value);
        $observerClassNames = [];

        foreach ($argType->getConstantStrings() as $stringType) {
            $observerClassNames[] = $stringType->getValue();
        }

        foreach ($argType->getConstantArrays() as $arrayType) {
            foreach ($arrayType->getValueTypes() as $valueType) {
                foreach ($valueType->getConstantStrings() as $stringType) {
                    $observerClassNames[] = $stringType->getValue();
                }
            }
        }

        $usages = [];

        foreach ($observerClassNames as $observerClassName) {
            foreach ([...self::OBSERVER_EVENT_METHODS, '__construct'] as $method) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef($observerClassName, $method, possibleDescendant: false),
                );
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getObserverUsagesFromModelAttribute(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();

        if (!(new ObjectType('Illuminate\Database\Eloquent\Model'))->isSuperTypeOf(new ObjectType($classReflection->getName()))->yes()) {
            return [];
        }

        $nativeReflection = $classReflection->getNativeReflection();
        $attributes = $nativeReflection->getAttributes('Illuminate\Database\Eloquent\Attributes\ObservedBy');

        $usages = [];

        foreach ($attributes as $attribute) {
            $args = $attribute->getArguments();

            foreach ($args as $arg) {
                $classNames = is_array($arg) ? $arg : [$arg];

                foreach ($classNames as $className) {
                    if (!is_string($className)) {
                        continue;
                    }

                    foreach ([...self::OBSERVER_EVENT_METHODS, '__construct'] as $method) {
                        $usages[] = new ClassMethodUsage(
                            UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Eloquent observer via #[ObservedBy]')),
                            new ClassMethodRef($className, $method, possibleDescendant: false),
                        );
                    }
                }
            }
        }

        return $usages;
    }

    private function shouldMarkAsUsed(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        return $this->isEloquentModelMethod($method, $classReflection)
            ?? $this->isFactoryMethod($method, $classReflection)
            ?? $this->isSeederMethod($method, $classReflection)
            ?? $this->isMigrationMethod($method, $classReflection);
    }

    private function isEloquentModelMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Eloquent\Model')) {
            return null;
        }

        $methodName = $method->getName();

        if ($method->isConstructor()) {
            return 'Eloquent model constructor';
        }

        if (in_array($methodName, ['boot', 'booted', 'casts', 'newFactory'], true)) {
            return 'Eloquent lifecycle/framework method';
        }

        if (str_starts_with($methodName, 'scope') && $methodName !== 'scope') {
            return 'Eloquent query scope';
        }

        if ($this->methodReturnsType($method, 'Illuminate\Database\Eloquent\Relations')) {
            return 'Eloquent relationship';
        }

        if ($this->methodReturnsExactType($method, 'Illuminate\Database\Eloquent\Casts\Attribute')) {
            return 'Eloquent attribute accessor';
        }

        if ($this->isLegacyAccessorOrMutator($methodName)) {
            return 'Eloquent legacy accessor/mutator';
        }

        return null;
    }

    /**
     * Legacy get{Name}Attribute() / set{Name}Attribute() convention (pre-Laravel 9).
     * Still supported in Laravel 10+ alongside the modern Attribute return-type approach.
     */
    private function isLegacyAccessorOrMutator(string $methodName): bool
    {
        $length = strlen($methodName);

        // Minimum: get + X + Attribute = 13 chars, same for set
        if ($length <= 12 || !str_ends_with($methodName, 'Attribute')) {
            return false;
        }

        return str_starts_with($methodName, 'get')
            || str_starts_with($methodName, 'set');
    }

    private function isFactoryMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Eloquent\Factories\Factory')) {
            return null;
        }

        if (in_array($method->getName(), ['definition', 'configure'], true)) {
            return 'Eloquent factory method';
        }

        return null;
    }

    private function isSeederMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Seeder')) {
            return null;
        }

        if ($method->getName() === 'run') {
            return 'Eloquent seeder method';
        }

        return null;
    }

    private function isMigrationMethod(
        ReflectionMethod $method,
        ClassReflection $classReflection,
    ): ?string
    {
        if (!$classReflection->is('Illuminate\Database\Migrations\Migration')) {
            return null;
        }

        if (in_array($method->getName(), ['up', 'down'], true)) {
            return 'Eloquent migration method';
        }

        return null;
    }

    /**
     * Checks if the method return type starts with the given prefix (for namespace matching).
     */
    private function methodReturnsType(
        ReflectionMethod $method,
        string $typePrefix,
    ): bool
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }

        return str_starts_with($returnType->getName(), $typePrefix);
    }

    /**
     * Checks if the method return type exactly matches the given type.
     */
    private function methodReturnsExactType(
        ReflectionMethod $method,
        string $type,
    ): bool
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }

        return $returnType->getName() === $type;
    }

    private function createUsage(
        ExtendedMethodReflection $methodReflection,
        string $reason,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

}
