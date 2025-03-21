<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Rule;

use LogicException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Command\Output;
use PHPStan\Diagnose\DiagnoseExtension;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ShipMonk\PHPStan\DeadCode\Collector\ClassDefinitionCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ConstantFetchCollector;
use ShipMonk\PHPStan\DeadCode\Collector\MethodCallCollector;
use ShipMonk\PHPStan\DeadCode\Collector\ProvidedUsagesCollector;
use ShipMonk\PHPStan\DeadCode\Compatibility\BackwardCompatibilityChecker;
use ShipMonk\PHPStan\DeadCode\Debug\DebugUsagePrinter;
use ShipMonk\PHPStan\DeadCode\Enum\ClassLikeKind;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\NeverReportedReason;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use ShipMonk\PHPStan\DeadCode\Error\BlackMember;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\CollectedUsage;
use ShipMonk\PHPStan\DeadCode\Hierarchy\ClassHierarchy;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_merge_recursive;
use function array_slice;
use function array_unique;
use function array_values;
use function in_array;
use function ksort;
use function strpos;

/**
 * @implements Rule<CollectedDataNode>
 */
class DeadCodeRule implements Rule, DiagnoseExtension
{

    public const IDENTIFIER_METHOD = 'shipmonk.deadMethod';
    public const IDENTIFIER_CONSTANT = 'shipmonk.deadConstant';

    private const UNSUPPORTED_MAGIC_METHODS = [
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

    private DebugUsagePrinter $debugUsagePrinter;

    private ClassHierarchy $classHierarchy;

    /**
     * typename => data
     *
     * @var array<string, array{
     *      kind: string,
     *      name: string,
     *      file: string,
     *      constants: array<string, array{line: int}>,
     *      methods: array<string, array{line: int, params: int, abstract: bool, visibility: int-mask-of<Visibility::*>}>,
     *      parents: array<string, null>,
     *      traits: array<string, array{excluded?: list<string>, aliases?: array<string, string>}>,
     *      interfaces: array<string, null>
     * }>
     */
    private array $typeDefinitions = [];

    /**
     * @var array<string, list<string>>
     */
    private array $memberAlternativesCache = [];

    private bool $reportTransitivelyDeadAsSeparateError;

    /**
     * memberKey => DeadMember
     *
     * @var array<string, BlackMember>
     */
    private array $blackMembers = [];

    /**
     * memberType => [memberName => CollectedUsage[]]
     *
     * @var array<MemberType::*, array<string, list<CollectedUsage>>>
     */
    private array $mixedMemberUsages = [];

    /**
     * @var array<string, array<string, non-empty-list<ClassMemberUsage>>> callerKey => array<calleeKey, usages[]>
     */
    private array $usageGraph = [];

    public function __construct(
        DebugUsagePrinter $debugUsagePrinter,
        ClassHierarchy $classHierarchy,
        bool $reportTransitivelyDeadMethodAsSeparateError,
        BackwardCompatibilityChecker $checker
    )
    {
        $this->debugUsagePrinter = $debugUsagePrinter;
        $this->classHierarchy = $classHierarchy;
        $this->reportTransitivelyDeadAsSeparateError = $reportTransitivelyDeadMethodAsSeparateError;

        $checker->check();
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(
        Node $node,
        Scope $scope
    ): array
    {
        if ($node->isOnlyFilesAnalysis()) {
            return [];
        }

        /** @var list<CollectedUsage> $knownCollectedUsages */
        $knownCollectedUsages = [];

        $methodDeclarationData = $node->get(ClassDefinitionCollector::class);
        $methodCallData = $node->get(MethodCallCollector::class);
        $constFetchData = $node->get(ConstantFetchCollector::class);
        $providedUsagesData = $node->get(ProvidedUsagesCollector::class);

        /** @var array<string, list<list<string>>> $memberUseData */
        $memberUseData = array_merge_recursive($methodCallData, $providedUsagesData, $constFetchData);
        unset($methodCallData, $providedUsagesData, $constFetchData);

        foreach ($memberUseData as $file => $usesPerFile) {
            foreach ($usesPerFile as $useStrings) {
                foreach ($useStrings as $useString) {
                    $collectedUsage = CollectedUsage::deserialize($useString, $file);
                    $memberUsage = $collectedUsage->getUsage();

                    if ($memberUsage->getMemberRef()->getClassName() === null) {
                        $this->mixedMemberUsages[$memberUsage->getMemberType()][$memberUsage->getMemberRef()->getMemberName()][] = $collectedUsage;
                        continue;
                    }

                    $knownCollectedUsages[] = $collectedUsage;
                }
            }
        }

        foreach ($methodDeclarationData as $file => $data) {
            foreach ($data as $typeData) {
                $typeName = $typeData['name'];
                $this->typeDefinitions[$typeName] = [
                    'kind' => $typeData['kind'],
                    'name' => $typeName,
                    'file' => $file,
                    'constants' => $typeData['constants'],
                    'methods' => $typeData['methods'],
                    'parents' => $typeData['parents'],
                    'traits' => $typeData['traits'],
                    'interfaces' => $typeData['interfaces'],
                ];
            }
        }

        unset($methodDeclarationData);

        foreach ($this->typeDefinitions as $typeName => $typeDefinition) {
            $methods = $typeDefinition['methods'];
            $constants = $typeDefinition['constants'];
            $file = $typeDefinition['file'];

            $ancestorNames = $this->getAncestorNames($typeName);

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeMethods($typeName));
            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($typeName), $this->getTypeConstants($typeName));
            $this->fillClassHierarchy($typeName, $ancestorNames);

            foreach ($methods as $methodName => $methodData) {
                $methodRef = new ClassMethodRef($typeName, $methodName, false);
                $methodKey = $methodRef->toKey();

                $this->blackMembers[$methodKey] = new BlackMember($methodRef, $file, $methodData['line']);

                foreach ($this->mixedMemberUsages[MemberType::METHOD][$methodName] ?? [] as $mixedUsage) {
                    $knownCollectedUsages[] = $mixedUsage->concretizeMixedUsage($typeName);
                }
            }

            foreach ($constants as $constantName => $constantData) {
                $constantRef = new ClassConstantRef($typeName, $constantName, false);
                $constantKey = $constantRef->toKey();

                $this->blackMembers[$constantKey] = new BlackMember($constantRef, $file, $constantData['line']);

                foreach ($this->mixedMemberUsages[MemberType::CONSTANT][$constantName] ?? [] as $mixedUsage) {
                    $knownCollectedUsages[] = $mixedUsage->concretizeMixedUsage($typeName);
                }
            }
        }

        /** @var array<string, non-empty-list<ClassMemberUsage>> $whiteMembers */
        $whiteMembers = [];
        /** @var list<CollectedUsage> $excludedMemberUsages */
        $excludedMemberUsages = [];

        foreach ($knownCollectedUsages as $collectedUsage) {
            if ($collectedUsage->isExcluded()) {
                $excludedMemberUsages[] = $collectedUsage;
                continue;
            }

            $memberUsage = $collectedUsage->getUsage();
            $isWhite = $this->isConsideredWhite($memberUsage);

            $alternativeMemberKeys = $this->getAlternativeMemberKeys($memberUsage->getMemberRef());
            $alternativeOriginKeys = $memberUsage->getOrigin()->hasClassMethodRef()
                ? $this->getAlternativeMemberKeys($memberUsage->getOrigin()->toClassMethodRef())
                : [];

            foreach ($alternativeMemberKeys as $alternativeMemberKey) {
                foreach ($alternativeOriginKeys as $alternativeOriginKey) {
                    $this->usageGraph[$alternativeOriginKey][$alternativeMemberKey][] = $memberUsage;
                }

                if ($isWhite) {
                    $whiteMembers[$alternativeMemberKey][] = $collectedUsage->getUsage();
                }
            }

            $this->debugUsagePrinter->recordUsage($collectedUsage, $alternativeMemberKeys);
        }

        foreach ($whiteMembers as $whiteCalleeKey => $usages) {
            $this->markTransitivesWhite([$whiteCalleeKey => $usages]);
        }

        foreach ($this->blackMembers as $blackMemberKey => $blackMember) {
            $neverReportedReason = $this->isNeverReportedAsDead($blackMember);

            if ($neverReportedReason !== null) {
                $this->debugUsagePrinter->markMemberAsNeverReported($blackMember, $neverReportedReason);

                unset($this->blackMembers[$blackMemberKey]);
            }
        }

        foreach ($excludedMemberUsages as $excludedMemberUsage) {
            $excludedBy = $excludedMemberUsage->getExcludedBy();
            $excludedMemberRef = $excludedMemberUsage->getUsage()->getMemberRef();
            $alternativeExcludedMemberKeys = $this->getAlternativeMemberKeys($excludedMemberRef);

            foreach ($alternativeExcludedMemberKeys as $alternativeExcludedMemberKey) {
                if (!isset($this->blackMembers[$alternativeExcludedMemberKey])) {
                    continue;
                }

                $this->blackMembers[$alternativeExcludedMemberKey]->markHasExcludedUsage($excludedBy);
            }

            $this->debugUsagePrinter->recordUsage($excludedMemberUsage, $alternativeExcludedMemberKeys);
        }

        if ($this->reportTransitivelyDeadAsSeparateError) {
            $errorGroups = array_map(static fn (BlackMember $member): array => [$member], $this->blackMembers);
        } else {
            $errorGroups = $this->groupDeadMembers();
        }

        $errors = [];

        foreach ($errorGroups as $deadGroup) {
            $errors[] = $this->buildError($deadGroup);
        }

        return $errors;
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenMethods
     */
    private function fillTraitMethodUsages(string $typeName, array $usedTraits, array $overriddenMethods): void
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

                $declaringTraitMethodDefinition = ClassMethodRef::buildKey($traitName, $traitMethod);
                $aliasMethodName = $adaptations['aliases'][$traitMethod] ?? null;

                // both method names need to work
                if ($aliasMethodName !== null) {
                    $aliasMethodDefinition = ClassMethodRef::buildKey($typeName, $aliasMethodName);
                    $this->classHierarchy->registerTraitUsage($declaringTraitMethodDefinition, $aliasMethodDefinition);
                }

                if (in_array($traitMethod, $excludedMethods, true)) {
                    continue; // was replaced by insteadof
                }

                $overriddenMethods[] = $traitMethod;
                $usedTraitMethodDefinition = ClassMethodRef::buildKey($typeName, $traitMethod);
                $this->classHierarchy->registerTraitUsage($declaringTraitMethodDefinition, $usedTraitMethodDefinition);
            }

            $this->fillTraitMethodUsages($typeName, $this->getTraitUsages($traitName), $overriddenMethods);
        }
    }

    /**
     * @param array<string, array{excluded?: list<string>, aliases?: array<string, string>}> $usedTraits
     * @param list<string> $overriddenConstants
     */
    private function fillTraitConstantUsages(string $typeName, array $usedTraits, array $overriddenConstants): void
    {
        foreach ($usedTraits as $traitName => $_) {
            $traitConstants = $this->typeDefinitions[$traitName]['constants'] ?? [];

            $excludedConstants = $overriddenConstants;

            foreach ($traitConstants as $traitConstant => $__) {
                $declaringTraitConstantKey = ClassConstantRef::buildKey($traitName, $traitConstant);

                if (in_array($traitConstant, $excludedConstants, true)) {
                    continue;
                }

                $overriddenConstants[] = $traitConstant;
                $traitUserConstantKey = ClassConstantRef::buildKey($typeName, $traitConstant);
                $this->classHierarchy->registerTraitUsage($declaringTraitConstantKey, $traitUserConstantKey);
            }

            $this->fillTraitConstantUsages($typeName, $this->getTraitUsages($traitName), $overriddenConstants);
        }
    }

    /**
     * @param list<string> $ancestorNames
     */
    private function fillClassHierarchy(string $typeName, array $ancestorNames): void
    {
        foreach ($ancestorNames as $ancestorName) {
            $this->classHierarchy->registerClassPair($ancestorName, $typeName);
        }
    }

    private function isAnonymousClass(?string $className): bool
    {
        // https://github.com/phpstan/phpstan/issues/8410 workaround, ideally this should not be ignored
        return $className !== null && strpos($className, 'AnonymousClass') === 0;
    }

    /**
     * @return list<string>
     */
    private function getAlternativeMemberKeys(ClassMemberRef $member): array
    {
        if ($member->getClassName() === null) {
            throw new LogicException('Those were eliminated above, should never happen');
        }

        $memberKey = $member->toKey();
        $possibleDescendant = $member->isPossibleDescendant();
        $cacheKey = $memberKey . ';' . ($possibleDescendant ? '1' : '0');

        if (isset($this->memberAlternativesCache[$cacheKey])) {
            return $this->memberAlternativesCache[$cacheKey];
        }

        $descendantsToCheck = $possibleDescendant ? $this->classHierarchy->getClassDescendants($member->getClassName()) : [];
        $meAndDescendants = [
            $member->getClassName(),
            ...$descendantsToCheck,
        ];

        $result = [];

        foreach ($meAndDescendants as $className) {
            $definerKey = $this->findDefinerMemberKey($member, $className);

            if ($definerKey !== null) {
                $result[] = $definerKey;
            }
        }

        $result = array_values(array_unique($result));

        $this->memberAlternativesCache[$cacheKey] = $result;

        return $result;
    }

    private function findDefinerMemberKey(
        ClassMemberRef $origin,
        string $className,
        bool $includeParentLookup = true
    ): ?string
    {
        $memberName = $origin->getMemberName();
        $memberKey = $origin::buildKey($className, $memberName);

        if ($this->hasMember($className, $memberName, $origin->getMemberType())) {
            return $memberKey;
        }

        // search for definition in traits
        $traitMethodKey = $this->classHierarchy->getDeclaringTraitMemberKey($memberKey);

        if ($traitMethodKey !== null) {
            return $traitMethodKey;
        }

        if ($includeParentLookup) {
            // search for definition in parents (and its traits)
            foreach ($this->getParentNames($className) as $parentName) {
                $found = $this->findDefinerMemberKey($origin, $parentName, false);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param non-empty-array<string, non-empty-list<ClassMemberUsage>> $stack callerKey => usages[]
     */
    private function markTransitivesWhite(array $stack): void
    {
        $callerKey = array_key_last($stack);
        $callees = $this->usageGraph[$callerKey] ?? [];

        if (isset($this->blackMembers[$callerKey])) {
            $this->debugUsagePrinter->markMemberAsWhite($this->blackMembers[$callerKey], $stack);

            unset($this->blackMembers[$callerKey]);
        }

        foreach ($callees as $calleeKey => $usages) {
            if (array_key_exists($calleeKey, $stack)) {
                continue;
            }

            if (!isset($this->blackMembers[$calleeKey])) {
                continue;
            }

            $this->markTransitivesWhite(array_merge($stack, [$calleeKey => $usages]));
        }
    }

    /**
     * @param array<string, null> $visitedKeys
     * @return list<string>
     */
    private function getTransitiveDeadCalls(string $callerKey, array $visitedKeys = []): array
    {
        $visitedKeys = $visitedKeys === [] ? [$callerKey => null] : $visitedKeys;
        $callees = $this->usageGraph[$callerKey] ?? [];

        $result = [];

        foreach ($callees as $calleeKey => $_) {
            if (array_key_exists($calleeKey, $visitedKeys)) {
                continue;
            }

            if (!isset($this->blackMembers[$calleeKey])) {
                continue;
            }

            $result[] = $calleeKey;

            foreach ($this->getTransitiveDeadCalls($calleeKey, array_merge($visitedKeys, [$calleeKey => null])) as $transitiveDead) {
                $result[] = $transitiveDead;
            }
        }

        return $result;
    }

    /**
     * @return list<non-empty-list<BlackMember>>
     */
    private function groupDeadMembers(): array
    {
        ksort($this->blackMembers);

        $deadGroups = [];

        /** @var array<string, true> $deadMethodsWithCaller */
        $deadMethodsWithCaller = [];

        foreach ($this->usageGraph as $caller => $callees) {
            if (!array_key_exists($caller, $this->blackMembers)) {
                continue;
            }

            foreach ($callees as $callee => $_) {
                if (array_key_exists($callee, $this->blackMembers)) {
                    $deadMethodsWithCaller[$callee] = true;
                }
            }
        }

        $methodsGrouped = [];

        foreach ($this->blackMembers as $deadMemberKey => $blackMember) {
            if (isset($methodsGrouped[$deadMemberKey])) {
                continue;
            }

            if (isset($deadMethodsWithCaller[$deadMemberKey])) {
                continue; // has a caller, thus should be part of a group, not a group representative
            }

            $deadGroups[$deadMemberKey][$deadMemberKey] = $blackMember;
            $methodsGrouped[$deadMemberKey] = true;

            $transitiveMethodKeys = $this->getTransitiveDeadCalls($deadMemberKey);

            foreach ($transitiveMethodKeys as $transitiveMethodKey) {
                $deadGroups[$deadMemberKey][$transitiveMethodKey] = $this->blackMembers[$transitiveMethodKey]; // @phpstan-ignore offsetAccess.notFound
                $methodsGrouped[$transitiveMethodKey] = true;
            }
        }

        // now only cycles remain, lets pick group representatives based on first occurrence
        foreach ($this->blackMembers as $deadMemberKey => $blackMember) {
            if (isset($methodsGrouped[$deadMemberKey])) {
                continue;
            }

            $transitiveDeadMethods = $this->getTransitiveDeadCalls($deadMemberKey);

            $deadGroups[$deadMemberKey][$deadMemberKey] = $blackMember;
            $methodsGrouped[$deadMemberKey] = true;

            foreach ($transitiveDeadMethods as $transitiveDeadMethodKey) {
                $deadGroups[$deadMemberKey][$transitiveDeadMethodKey] = $this->blackMembers[$transitiveDeadMethodKey]; // @phpstan-ignore offsetAccess.notFound
                $methodsGrouped[$transitiveDeadMethodKey] = true;
            }
        }

        return array_map('array_values', array_values($deadGroups));
    }

    /**
     * @param non-empty-list<BlackMember> $blackMembersGroup
     */
    private function buildError(array $blackMembersGroup): IdentifierRuleError
    {
        $representative = $blackMembersGroup[0];

        $humanMemberString = $representative->getMember()->toHumanString();
        $exclusionMessage = $representative->getExclusionMessage();

        $builder = RuleErrorBuilder::message("Unused {$humanMemberString}{$exclusionMessage}")
            ->file($representative->getFile())
            ->line($representative->getLine())
            ->identifier($representative->getErrorIdentifier());

        $metadata = [];
        $metadata[$humanMemberString] = [
            'file' => $representative->getFile(),
            'line' => $representative->getLine(),
            'transitive' => false,
        ];

        $tips = [];

        foreach (array_slice($blackMembersGroup, 1) as $transitivelyDeadMember) {
            $transitiveDeadMemberRef = $transitivelyDeadMember->getMember()->toHumanString();
            $exclusionMessage = $transitivelyDeadMember->getExclusionMessage();

            $tips[$transitiveDeadMemberRef] = "Thus $transitiveDeadMemberRef is transitively also unused{$exclusionMessage}";
            $metadata[$transitiveDeadMemberRef] = [
                'file' => $transitivelyDeadMember->getFile(),
                'line' => $transitivelyDeadMember->getLine(),
                'transitive' => true,
            ];
        }

        $builder->metadata($metadata);

        ksort($tips);

        foreach ($tips as $tip) {
            $builder->addTip($tip);
        }

        return $builder->build();
    }

    /**
     * @return list<string>
     */
    private function getParentNames(string $typeName): array
    {
        return array_keys($this->typeDefinitions[$typeName]['parents'] ?? []);
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
     * @param MemberType::* $memberType
     */
    private function hasMember(string $typeName, string $memberName, int $memberType): bool
    {
        if ($memberType === MemberType::METHOD) {
            $key = 'methods';
        } elseif ($memberType === MemberType::CONSTANT) {
            $key = 'constants';
        } else {
            throw new LogicException('Invalid member type');
        }

        return array_key_exists($memberName, $this->typeDefinitions[$typeName][$key] ?? []);
    }

    /**
     * @return array<string, array{excluded?: list<string>, aliases?: array<string, string>}>
     */
    private function getTraitUsages(string $typeName): array
    {
        return $this->typeDefinitions[$typeName]['traits'] ?? [];
    }

    private function isConsideredWhite(ClassMemberUsage $memberUsage): bool
    {
        return $memberUsage->getOrigin()->getClassName() === null // out-of-class scope
            || $this->isAnonymousClass($memberUsage->getOrigin()->getClassName())
            || (array_key_exists((string) $memberUsage->getOrigin()->getMethodName(), self::UNSUPPORTED_MAGIC_METHODS));
    }

    /**
     * @return NeverReportedReason::*|null
     */
    private function isNeverReportedAsDead(BlackMember $blackMember): ?string
    {
        if (!$blackMember->getMember() instanceof ClassMethodRef) {
            return null;
        }

        $typeName = $blackMember->getMember()->getClassName();
        $memberName = $blackMember->getMember()->getMemberName();

        if ($typeName === null) {
            throw new LogicException('Ensured by BlackMember constructor');
        }

        $kind = $this->typeDefinitions[$typeName]['kind'] ?? null;
        $params = $this->typeDefinitions[$typeName]['methods'][$memberName]['params'] ?? 0;
        $abstract = $this->typeDefinitions[$typeName]['methods'][$memberName]['abstract'] ?? false;
        $visibility = $this->typeDefinitions[$typeName]['methods'][$memberName]['visibility'] ?? 0;

        if ($kind === ClassLikeKind::TRAIT && $abstract) {
            // abstract methods in traits make sense (not dead) only when called within the trait itself, but that is hard to detect for now, so lets ignore them completely
            // the difference from interface methods (or abstract methods) is that those methods can be called over the interface, but you cannot call method over trait
            return NeverReportedReason::ABSTRACT_TRAIT_METHOD;
        }

        if ($memberName === '__construct' && ($visibility & Visibility::PRIVATE) !== 0 && $params === 0) {
            // private constructors with zero parameters are often used to deny instantiation
            return NeverReportedReason::PRIVATE_CONSTRUCTOR_NO_PARAMS;
        }

        if (array_key_exists($memberName, self::UNSUPPORTED_MAGIC_METHODS)) {
            return NeverReportedReason::UNSUPPORTED_MAGIC_METHOD;
        }

        return null;
    }

    public function print(Output $output): void
    {
        $this->debugUsagePrinter->printMixedMemberUsages($output, $this->mixedMemberUsages);
        $this->debugUsagePrinter->printDebugMemberUsages($output, $this->typeDefinitions);
    }

}
