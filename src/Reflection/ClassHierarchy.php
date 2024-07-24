<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Reflection;

use PHPStan\Reflection\ClassReflection;
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
     * parentMethodKey => childrenMethodKey[] that can mark parent as used
     *
     * @var array<string, list<string>>
     */
    private array $methodDescendants = [];

    /**
     * traitMethodKey => traitUserMethodKey[]
     *
     * @var array<string, list<string>>
     */
    private array $methodTraitUsages = [];

    public function registerClassPair(ClassReflection $ancestor, ClassReflection $descendant): void
    {
        $this->classDescendants[$ancestor->getName()][$descendant->getName()] = true;
    }

    public function registerMethodPair(string $ancestorMethodKey, string $descendantMethodKey): void
    {
        $this->methodDescendants[$ancestorMethodKey][] = $descendantMethodKey;
    }

    public function registerMethodTraitUsage(string $declaringTraitMethodKey, string $methodKey): void
    {
        $this->methodTraitUsages[$declaringTraitMethodKey][] = $methodKey;
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

    /**
     * @return list<string>
     */
    public function getMethodDescendants(string $methodKey): array
    {
        return $this->methodDescendants[$methodKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getMethodTraitUsages(string $traitMethodKey): array
    {
        return $this->methodTraitUsages[$traitMethodKey] ?? [];
    }

}
