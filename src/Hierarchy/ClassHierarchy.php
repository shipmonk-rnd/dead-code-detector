<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Hierarchy;

use ShipMonk\PHPStan\DeadCode\Crate\MethodDefinition;
use function array_keys;

class ClassHierarchy
{

    /**
     * parentClassName => childrenClassName[]
     *
     * @var array<string, array<string, true>>
     */
    private array $classDescendants = [];

    /**
     * traitUserMethodKey => declaringTraitMethodKey
     *
     * @var array<string, MethodDefinition>
     */
    private array $declaringTraits = [];

    public function registerClassPair(string $ancestorName, string $descendantName): void
    {
        $this->classDescendants[$ancestorName][$descendantName] = true;
    }

    public function registerMethodTraitUsage(
        MethodDefinition $declaringTraitMethodKey,
        MethodDefinition $traitUsageMethodKey
    ): void
    {
        $this->declaringTraits[$traitUsageMethodKey->toString()] = $declaringTraitMethodKey;
    }

    /**
     * @return list<string>
     */
    public function getClassDescendants(string $className): array
    {
        return isset($this->classDescendants[$className])
            ? array_keys($this->classDescendants[$className])
            : [];
    }

    public function getDeclaringTraitMethodDefinition(MethodDefinition $definition): ?MethodDefinition
    {
        return $this->declaringTraits[$definition->toString()] ?? null;
    }

}
