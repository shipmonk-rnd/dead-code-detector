<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Processor;

use LogicException;
use PHPStan\Node\CollectedDataNode;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\PropertyAccessCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ProvidedUsagesCollector;
use ShipMonk\PHPStan\DeadCode\Debug\DebugUsagePrinter;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_merge_recursive;
use function array_unique;
use function array_values;
use function in_array;
use function serialize;
use function strpos;

final class CollectedDataProcessor
{

    public const UNSUPPORTED_MAGIC_METHODS = [
        '__invoke' => null,
        '__toString' => null,
        '__destruct' => null,
        '__call' => null,
        '__callStatic' => null,
        '__get' => null,
        '__set' => null,
        '__isset' => null,
        '__unset' => null,
        '__sleep' => null,
        '__wakeup' => null,
        '__serialize' => null,
        '__unserialize' => null,
        '__set_state' => null,
        '__debugInfo' => null,
    ];

    private ClassHierarchy $classHierarchy;

    private ?CollectedDataNode $processedNode = null;

    /**
     * typename => data
     *
     * @var array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      cases: array<string, array{line: int}>,
     *      constants: array<string, array{line: int, visibility: int-mask-of<Visibility::*>}>,
     *      properties: array<string, array{line: int, default: bool, virtual: bool, setHook: bool, visibility: int-mask-of<Visibility::*>}>,
     *      methods: array<string, array{line: int, params: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    private array $typeDefinitions = [];

    /**
     * type => [trait user => [trait => [aliased_member_name => original_member_name]]]
     *
     * @var array<MemberType::*, array<string, array<string, array<string, string>>>>
     */
    private array $traitMembers = [];

    /**
     * @var array<string, list<string>>
     */
    private array $memberAlternativesCache = [];

    /**
     * @var list<CollectedUsage>
     */
    private array $knownCollectedUsages = [];

    /**
     * @var list<CollectedUsage>
     */
    private array $excludedCollectedUsages = [];

    /**
     * memberType => [memberName => CollectedUsage[]]
     *
     * @var array<MemberType::*, array<string, non-empty-list<CollectedUsage>>>
     */
    private array $mixedClassNameUsages = [];

    public function __construct(ClassHierarchy $classHierarchy)
    {
        $this->classHierarchy = $classHierarchy;
    }

    public function processCollectedData(CollectedDataNode $node): void
    {
        if ($this->processedNode === $node || $node->isOnlyFilesAnalysis()) {
            return;
        }

        // Reset state for new node
        $this->typeDefinitions = [];
        $this->traitMembers = [];
        $this->memberAlternativesCache = [];
        $this->knownCollectedUsages = [];
        $this->excludedCollectedUsages = [];
        $this->mixedClassNameUsages = [];

        /** @var list<CollectedUsage> $allKnownUsages */
        $allKnownUsages = [];

        $classDefinitionData = $node->get(ClassDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);
        $constFetchData = $node->get(ConstantFetchCollector::class);
        $propertyAccessData = $node->get(PropertyAccessCollector::class);
        $providedUsagesData = $node->get(ProvidedUsagesCollector::class);

        /** @var array<string, list<list<string>>> $memberUseData */
        $memberUseData = array_merge_recursive($methodCallData, $providedUsagesData, $constFetchData, $propertyAccessData);
        unset($methodCallData, $providedUsagesData, $constFetchData, $propertyAccessData);

        foreach ($memberUseData as $file => $usesPerFile) {
            foreach ($usesPerFile as $useStrings) {
                foreach ($useStrings as $useString) {
                    $collectedUsage = CollectedUsage::deserialize($useString, $file);
                    $memberUsage = $collectedUsage->getUsage();
                    $className = $memberUsage->getMemberRef()->getClassName();
                    $memberName = $memberUsage->getMemberRef()->getMemberName();

                    if ($className === null) {
                        $memberNameString = $memberName ?? DebugUsagePrinter::ANY_MEMBER;
                        $this->mixedClassNameUsages[$memberUsage->getMemberType()][$memberNameString][] = $collectedUsage;
                        continue;
                    }

                    $allKnownUsages[] = $collectedUsage;
                }
            }
        }

        foreach ($classDefinitionData as $file => $data) {
            foreach ($data as $typeData) {
                $typeName = $typeData['name'];
                $this->typeDefinitions[$typeName] = [
                    'kind' => $typeData['kind'],
                    'name' => $typeName,
                    'file' => $file,
                    'cases' => $typeData['cases'],
                    'constants' => $typeData['constants'],
                    'properties' => $typeData['properties'],
                    'methods' => $typeData['methods'],
                    'parents' => $typeData['parents'],
                    'traits' => $typeData['traits'],
                    'interfaces' => $typeData['interfaces'],
                ];
            }
        }

        unset($classDefinitionData);

        foreach ($this->typeDefinitions as $typeName => $typeDefinition) {
            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeMethods($typeName));
            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeConstants($typeName));
            $this->fillTraitPropertyUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeProperties($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);
        }

        // Expand mixed usages into concrete usages
        foreach ($this->typeDefinitions as $typeName => $typeDef) {
            $memberNamesForMixedExpand = [
                MemberType::METHOD => array_keys($typeDef['methods']),
                MemberType::CONSTANT => array_merge(
                    array_keys($typeDef['constants']),
                    array_keys($typeDef['cases']),
                ),
                MemberType::PROPERTY => array_keys($typeDef['properties']),
            ];

            foreach ($memberNamesForMixedExpand as $memberType => $memberNames) {
                foreach ($memberNames as $memberName) {
                    foreach ($this->mixedClassNameUsages[$memberType][$memberName] ?? [] as $mixedUsage) {
                        $allKnownUsages[] = $mixedUsage->concretizeMixedClassNameUsage($typeName);
                    }
                }
            }
        }

        // Separate excluded usages from known usages
        foreach ($allKnownUsages as $collectedUsage) {
            if ($collectedUsage->isExcluded()) {
                $this->excludedCollectedUsages[] = $collectedUsage;
            } else {
                $this->knownCollectedUsages[] = $collectedUsage;
            }
        }

        $this->processedNode = $node;
    }

    /**
     * @return array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      cases: array<string, array{line: int}>,
     *      constants: array<string, array{line: int, visibility: int-mask-of<Visibility::*>}>,
     *      properties: array<string, array{line: int, default: bool, virtual: bool, setHook: bool, visibility: int-mask-of<Visibility::*>}>,
     *      methods: array<string, array{line: int, params: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    public function getTypeDefinitions(): array
    {
        return $this->typeDefinitions;
    }

    /**
     * @return array<MemberType::*, array<string, array<string, array<string, string>>>>
     */
    public function getTraitMembers(): array
    {
        return $this->traitMembers;
    }

    /**
     * @return list<CollectedUsage>
     */
    public function getKnownCollectedUsages(): array
    {
        return $this->knownCollectedUsages;
    }

    /**
     * @return list<CollectedUsage>
     */
    public function getExcludedCollectedUsages(): array
    {
        return $this->excludedCollectedUsages;
    }

    /**
     * @return array<MemberType::*, array<string, non-empty-list<CollectedUsage>>>
     */
    public function getMixedClassNameUsages(): array
    {
        return $this->mixedClassNameUsages;
    }

    /**
     * @param ClassMemberRef<?string, ?string> $member
     * @param AccessType::* $accessType
     * @return list<string>
     */
    public function getAlternativeMemberKeys(
        ClassMemberRef $member,
        int $accessType
    ): array
    {
        if (!$member->hasKnownClass()) {
            throw new LogicException('Those were eliminated above, should never happen');
        }

        $cacheKey = serialize([$member, $accessType]);

        if (isset($this->memberAlternativesCache[$cacheKey])) {
            return $this->memberAlternativesCache[$cacheKey];
        }

        $descendantsToCheck = $member->isPossibleDescendant() ? $this->classHierarchy->getClassDescendants($member->getClassName()) : [];
        $meAndDescendants = [
            $member->getClassName(),
            ...$descendantsToCheck,
        ];

        $result = [];

        foreach ($meAndDescendants as $className) {
            if ($member->getMemberName() !== null) {
                foreach ($this->findDefinerKeys($member->withKnownNames($className, $member->getMemberName()), $accessType) as $definerKey) {
                    $result[] = $definerKey;
                }

            } else {
                foreach ($this->getPossibleDefinerKeys($member->withKnownClass($className), $accessType) as $possibleDefinerKey) {
                    $result[] = $possibleDefinerKey;
                }
            }
        }

        $result = array_values(array_unique($result));

        $this->memberAlternativesCache[$cacheKey] = $result;

        return $result;
    }

    public function isAnonymousClass(?string $className): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return $className !== null && strpos($className, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getAncestorNames(string $typeName): array
    {
        return array_merge(
            array_keys($this->typeDefinitions[$typeName]['parents'] ?? []),
            array_keys($this->typeDefinitions[$typeName]['interfaces'] ?? []),
        );
    }

    /**
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraitUsages(string $typeName): array
    {
        return $this->typeDefinitions[$typeName]['traits'] ?? [];
    }

    /**
     * @return list<string>
     */
    private function getTypeMethods(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['methods'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function getTypeConstants(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['constants'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function getTypeProperties(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['properties'] ?? []);
    }

    /**
     * @param ClassMemberRef<string, string> $memberRef
     */
    private function isExistingRef(
        ClassMemberRef $memberRef
    ): bool
    {
        $typeName = $memberRef->getClassName();
        $memberName = $memberRef->getMemberName();

        $keys = $this->getTypeDefinitionKeysForMemberType($memberRef);

        foreach ($keys as $key) {
            if (array_key_exists($memberName, $this->typeDefinitions[$typeName][$key] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassMemberRef<string, ?string> $memberRef
     * @return list<string>
     */
    private function getMemberNames(
        ClassMemberRef $memberRef
    ): array
    {
        $typeName = $memberRef->getClassName();
        $keys = $this->getTypeDefinitionKeysForMemberType($memberRef);

        $result = [];

        foreach ($keys as $key) {
            $result = [
                ...$result,
                ...array_keys($this->typeDefinitions[$typeName][$key] ?? []),
            ];

        }

        return array_values(array_unique($result));
    }

    /**
     * @param ClassMemberRef<string, ?string> $memberRef
     * @return list<'methods'|'constants'|'cases'|'properties'>
     */
    private function getTypeDefinitionKeysForMemberType(ClassMemberRef $memberRef): array
    {
        if ($memberRef instanceof ClassMethodRef) {
            return ['methods'];
        } elseif ($memberRef instanceof ClassConstantRef) {
            if ($memberRef->isEnumCase()->yes()) {
                return ['cases'];
            } elseif ($memberRef->isEnumCase()->no()) {
                return ['constants'];
            } else {
                return ['constants', 'cases'];
            }
        } elseif ($memberRef instanceof ClassPropertyRef) {
            return ['properties'];
        }

        throw new LogicException('Invalid member type');
    }

    /**
     * @param ClassMemberRef<string, string> $memberRef
     * @param AccessType::* $accessType
     * @return list<string>
     */
    private function findDefinerKeys(
        ClassMemberRef $memberRef,
        int $accessType,
        bool $includeParentLookup = true
    ): array
    {
        if ($this->isExistingRef($memberRef)) {
            return $memberRef->toKeys($accessType);
        }

        // search for definition in traits
        $traitMethodKeys = $this->getDeclaringTraitKeys($memberRef, $accessType);

        if ($traitMethodKeys !== []) {
            return $traitMethodKeys;
        }

        if ($includeParentLookup) {
            $parentNames = $this->getAncestorNames($memberRef->getClassName());

            // search for definition in parents (and its traits)
            foreach ($parentNames as $parentName) {
                $found = $this->findDefinerKeys($memberRef->withKnownClass($parentName), $accessType, false);

                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    /**
     * @param ClassMemberRef<string, ?string> $memberRef
     * @param AccessType::* $accessType
     * @param array<string, true> $foundMemberNames Reference needed to ensure first parent takes the usage
     * @return list<string>
     */
    private function getPossibleDefinerKeys(
        ClassMemberRef $memberRef,
        int $accessType,
        bool $includeParentLookup = true,
        array &$foundMemberNames = []
    ): array
    {
        /** @var list<string> $result */
        $result = [];
        $className = $memberRef->getClassName();
        $memberType = $memberRef->getMemberType();

        foreach ($this->getMemberNames($memberRef) as $memberName) {
            $memberKeys = $memberRef->withKnownMember($memberName)->toKeys($accessType);

            if (isset($foundMemberNames[$memberName])) {
                continue;
            }

            foreach ($memberKeys as $memberKey) {
                $result[] = $memberKey;
            }
            $foundMemberNames[$memberName] = true;
        }

        // search for definition in traits
        foreach ($this->traitMembers[$memberType][$className] ?? [] as $traitName => $traitMemberNames) {
            foreach ($traitMemberNames as $aliasedMemberName => $traitMemberName) {
                if (isset($foundMemberNames[$aliasedMemberName])) {
                    continue;
                }

                $traitKeys = $memberRef->withKnownNames($traitName, $traitMemberName)->toKeys($accessType);

                foreach ($traitKeys as $traitKey) {
                    $result[] = $traitKey;
                }
                $foundMemberNames[$aliasedMemberName] = true;
            }
        }

        if ($includeParentLookup) {
            $parentNames = $this->getAncestorNames($className);

            // search for definition in parents (and its traits)
            foreach ($parentNames as $parentName) {
                $result = [
                    ...$result,
                    ...$this->getPossibleDefinerKeys($memberRef->withKnownClass($parentName), $accessType, false, $foundMemberNames),
                ];
            }
        }

        return $result;
    }

    /**
     * @param ClassMemberRef<string, string> $memberRef
     * @param AccessType::* $accessType
     * @return list<string>
     */
    private function getDeclaringTraitKeys(
        ClassMemberRef $memberRef,
        int $accessType
    ): array
    {
        $memberType = $memberRef->getMemberType();
        $className = $memberRef->getClassName();
        $memberName = $memberRef->getMemberName();

        foreach ($this->traitMembers[$memberType][$className] ?? [] as $traitName => $traitMemberNames) {
            foreach ($traitMemberNames as $aliasedMemberName => $traitMemberName) {
                if ($memberName === $aliasedMemberName) {
                    return $memberRef->withKnownNames($traitName, $traitMemberName)->toKeys($accessType);
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenMethods
     */
    private function fillTraitMethodUsages(
        string $typeName,
        array $usedTraits,
        array $overriddenMethods
    ): void
    {
        foreach ($usedTraits as $traitName => $adaptations) {
            $traitMethods = $this->typeDefinitions[$traitName]['methods'] ?? [];

            $excludedMethods = array_merge(
                $overriddenMethods,
                $adaptations['excluded'] ?? [],
            );

            foreach ($traitMethods as $traitMethod => $traitMethodData) {
                if ($traitMethodData['abstract']) {
                    continue; // abstract trait methods are ignored, should correlate with isNeverReportedAsDead
                }

                $aliasMethodName = $adaptations['aliases'][$traitMethod] ?? null;

                // both method names need to work
                if ($aliasMethodName !== null) {
                    $this->traitMembers[MemberType::METHOD][$typeName][$traitName][$aliasMethodName] = $traitMethod;
                }

                if (in_array($traitMethod, $excludedMethods, true)) {
                    continue; // was replaced by insteadof
                }

                $overriddenMethods[] = $traitMethod;
                $this->traitMembers[MemberType::METHOD][$typeName][$traitName][$traitMethod] = $traitMethod;
            }

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($traitName), $overriddenMethods);
        }
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenConstants
     */
    private function fillTraitConstantUsages(
        string $typeName,
        array $usedTraits,
        array $overriddenConstants
    ): void
    {
        foreach ($usedTraits as $traitName => $traitInfo) {
            $traitConstants = $this->typeDefinitions[$traitName]['constants'] ?? [];

            $excludedConstants = $overriddenConstants;

            foreach ($traitConstants as $traitConstant => $constantInfo) {
                if (in_array($traitConstant, $excludedConstants, true)) {
                    continue;
                }

                $overriddenConstants[] = $traitConstant;
                $this->traitMembers[MemberType::CONSTANT][$typeName][$traitName][$traitConstant] = $traitConstant;
            }

            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($traitName), $overriddenConstants);
        }
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenProperties
     */
    private function fillTraitPropertyUsages(
        string $typeName,
        array $usedTraits,
        array $overriddenProperties
    ): void
    {
        foreach ($usedTraits as $traitName => $traitInfo) {
            $traitProperties = $this->typeDefinitions[$traitName]['properties'] ?? [];

            $excludedProperties = $overriddenProperties;

            foreach ($traitProperties as $traitConstant => $constantInfo) {
                if (in_array($traitConstant, $excludedProperties, true)) {
                    continue;
                }

                $overriddenProperties[] = $traitConstant;
                $this->traitMembers[MemberType::PROPERTY][$typeName][$traitName][$traitConstant] = $traitConstant;
            }

            $this->fillTraitPropertyUsages($typeName, $this->getTraitUsages($traitName), $overriddenProperties);
        }
    }

    /**
     * @param list<string> $ancestorNames
     */
    private function fillClassHierarchy(
        string $typeName,
        array $ancestorNames
    ): void
    {
        foreach ($ancestorNames as $ancestorName) {
            $this->classHierarchy->registerClassPair($ancestorName, $typeName);
        }
    }

}
