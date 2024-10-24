<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Hierarchy;

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
     * @var array<string, string>
     */
    private array $declaringTraits = [];

    public function registerClassPair(string $ancestorName, string $descendantName): void
    {
        $this->classDescendants[$ancestorName][$descendantName] = true;
    }

    public function registerMethodTraitUsage(
        string $declaringTraitMethodKey,
        string $traitUsageMethodKey
    ): void
    {
        $this->declaringTraits[$traitUsageMethodKey] = $declaringTraitMethodKey;
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

    public function getDeclaringTraitMethodKey(string $methodKey): ?string
    {
        return $this->declaringTraits[$methodKey] ?? null;
    }

}
