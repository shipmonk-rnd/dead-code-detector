<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Reflection;

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
     * parentMethodKey => childrenMethodKey[]
     *
     * @var array<string, list<string>>
     */
    private array $methodDescendants = [];

    /**
     * childrenMethodKey => parentMethodKey[]
     *
     * @var array<string, list<string>>
     */
    private array $methodAncestors = [];

    /**
     * traitMethodKey => traitUserMethodKey[]
     *
     * @var array<string, list<string>>
     */
    private array $methodTraitUsages = [];

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

    public function registerMethodPair(string $ancestorMethodKey, string $descendantMethodKey): void
    {
        $this->methodDescendants[$ancestorMethodKey][] = $descendantMethodKey;
        $this->methodAncestors[$descendantMethodKey][] = $ancestorMethodKey;
    }

    public function registerMethodTraitUsage(string $declaringTraitMethodKey, string $traitUsageMethodKey): void
    {
        $this->methodTraitUsages[$declaringTraitMethodKey][] = $traitUsageMethodKey;
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
    public function getMethodAncestors(string $methodKey): array
    {
        return $this->methodAncestors[$methodKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getMethodTraitUsages(string $traitMethodKey): array
    {
        return $this->methodTraitUsages[$traitMethodKey] ?? [];
    }

    public function getDeclaringTraitMethodKey(string $methodKey): ?string
    {
        return $this->declaringTraits[$methodKey] ?? null;
    }

}
